<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\BpLineRequirementException;
use App\Domains\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domains\Accounting\Exceptions\CostCenterNotPostableException;
use App\Domains\Accounting\Exceptions\InvalidOpenItemException;
use App\Domains\Accounting\Exceptions\JournalEntryNotDraftException;
use App\Domains\Accounting\Exceptions\JournalEntryNotPostedException;
use App\Domains\Accounting\Exceptions\MissingBusinessPartnerException;
use App\Domains\Accounting\Exceptions\MissingCostAllocationRuleException;
use App\Domains\Accounting\Exceptions\NonPostingAccountException;
use App\Domains\Accounting\Exceptions\NoOpenFiscalPeriodException;
use App\Domains\Accounting\Exceptions\NumberSeriesExhaustedException;
use App\Domains\Accounting\Exceptions\TaxRateNotEffectiveException;
use App\Domains\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Domains\Accounting\Exceptions\UnreversibleJournalEntryException;
use App\Domains\Accounting\Models\AccountReconciliation;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\AccountReconciliationService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Exceptions\OpenItemOverpaymentException;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BpPaymentApplication;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Tax\Models\TaxRate;
use App\Models\User;

function contappFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '1-01-01-01-001',
        'accepts_posting' => true,
    ]);

    $capital = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '3-01-01-01-001',
        'accepts_posting' => true,
    ]);

    $documentType = DocumentType::factory()->create([
        'company_id' => $company->id,
        'code' => 'ADD',
    ]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    $period = FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    return compact('company', 'cash', 'capital', 'documentType', 'fiscalYear', 'period');
}

it('contabiliza un asiento balanceado en las 3 monedas', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $service = app(PostJournalService::class);

    $entry = $service->post(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 520, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 520),
        ],
        'Aporte de capital'
    );

    expect($entry->status)->toBe('posted')
        ->and($entry->document_number)->toBe(1)
        ->and($entry->details)->toHaveCount(2);

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);

    expect($cashDetail->debit_local)->toEqual('520.00')
        ->and($cashDetail->debit_foreign)->toEqual('1.00')
        ->and($cashDetail->debit_system)->toEqual('1.00');
});

it('guarda un documento de referencia y su fecha distintos por línea, para un asiento que junta varios documentos', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput(
                $cash->id, $company->local_currency_id, debit: 300, credit: 0,
                referenceDocument: 'Factura-4521', referenceDocumentDate: '2026-01-10',
            ),
            new JournalLineInput(
                $capital->id, $company->local_currency_id, debit: 0, credit: 300,
                referenceDocument: 'Factura-4522', referenceDocumentDate: '2026-01-12',
            ),
        ],
        'Dos facturas distintas en el mismo asiento'
    );

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);
    $capitalDetail = $entry->details->firstWhere('account_id', $capital->id);

    expect($cashDetail->reference_document)->toBe('Factura-4521')
        ->and($cashDetail->reference_document_date->format('Y-m-d'))->toBe('2026-01-10')
        ->and($capitalDetail->reference_document)->toBe('Factura-4522')
        ->and($capitalDetail->reference_document_date->format('Y-m-d'))->toBe('2026-01-12')
        // La fecha de contabilización del asiento sigue siendo una sola,
        // independiente de las fechas de los documentos que junta.
        ->and($entry->posting_date->format('Y-m-d'))->toBe('2026-01-15');
});

it('guarda un asiento en borrador (saveDraft) con documento de referencia y fecha por línea', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->saveDraft(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput(
                $cash->id, $company->local_currency_id, debit: 300, credit: 0,
                referenceDocument: 'Recibo-01', referenceDocumentDate: '2026-01-09', allowZeroAmount: true,
            ),
        ],
        'Borrador con documento de referencia'
    );

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);

    expect($entry->status)->toBe('draft')
        ->and($cashDetail->reference_document)->toBe('Recibo-01')
        ->and($cashDetail->reference_document_date->format('Y-m-d'))->toBe('2026-01-09');
});

it('rechaza un asiento que no cuadra', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $service = app(PostJournalService::class);

    $service->post(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 520, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
    );
})->throws(UnbalancedJournalEntryException::class);

it('rechaza contabilizar contra una cuenta que no acepta movimientos', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();

    $summary = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '1-01-01-01-000',
        'accepts_posting' => false,
    ]);

    $service = app(PostJournalService::class);

    $service->post(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($summary->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
    );
})->throws(NonPostingAccountException::class);

it('rechaza contabilizar en un período cerrado', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType, 'fiscalYear' => $fiscalYear] = contappFixture();

    FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->update(['status' => 'closed']);

    $service = app(PostJournalService::class);

    $service->post(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
    );
})->throws(ClosedFiscalPeriodException::class);

it('rechaza contabilizar en una fecha sin período fiscal configurado', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $service = app(PostJournalService::class);

    $service->post(
        $company,
        $documentType,
        new DateTime('2027-06-01'),
        new DateTime('2027-06-01'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
    );
})->throws(NoOpenFiscalPeriodException::class);

it('numera los documentos de forma correlativa por tipo de documento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $service = app(PostJournalService::class);

    $first = $service->post($company, $documentType, new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 50, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 50),
    ]);

    $second = $service->post($company, $documentType, new DateTime('2026-01-11'), new DateTime('2026-01-11'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 75, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 75),
    ]);

    expect($first->document_number)->toBe(1)
        ->and($second->document_number)->toBe(2);
});

it('rechaza contabilizar sin socio de negocio en una cuenta que lo exige', function () {
    ['company' => $company, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '1-01-02-01-001',
        'accepts_posting' => true,
        'requires_business_partner' => true,
    ]);

    $service = app(PostJournalService::class);

    $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(MissingBusinessPartnerException::class);

it('rechaza contabilizar sin norma de reparto en una cuenta que lo exige', function () {
    ['company' => $company, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $gasto = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '6-01-01-01-001',
        'accepts_posting' => true,
        'requires_cost_center' => true,
    ]);

    $service = app(PostJournalService::class);

    $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($gasto->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(MissingCostAllocationRuleException::class);

it('contabiliza una línea con norma de reparto: la explota en una fila real por centro de costo', function () {
    ['company' => $company, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $gasto = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '6-01-01-01-001',
        'accepts_posting' => true,
        'requires_cost_center' => true,
    ]);
    $ccA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-A']);
    $ccB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-B']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $ccA->id, 'percentage' => '60.00', 'position' => 1]);
    $rule->lines()->create(['cost_center_id' => $ccB->id, 'percentage' => '40.00', 'position' => 2]);

    $service = app(PostJournalService::class);

    $entry = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($gasto->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $gastoDetails = $entry->details->where('account_id', $gasto->id);

    expect($gastoDetails)->toHaveCount(2)
        ->and($gastoDetails->firstWhere('cost_center_id', $ccA->id)->debit_local)->toEqual('60.00')
        ->and($gastoDetails->firstWhere('cost_center_id', $ccB->id)->debit_local)->toEqual('40.00')
        ->and($gastoDetails->every(fn ($d) => $d->cost_allocation_rule_id === $rule->id))->toBeTrue()
        ->and($gastoDetails->reduce(fn ($c, $d) => bcadd($c, (string) $d->debit_local, 2), '0.00'))->toEqual('100.00');
});

it('reparte una línea con redondeo entre 8 centros de costo y reconcilia exacto contra el monto original', function () {
    ['company' => $company, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $gasto = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '6-01-01-01-001',
        'accepts_posting' => true,
        'requires_cost_center' => true,
    ]);

    // Mismos porcentajes del ejemplo de referencia (PDF SAP B1, norma
    // TRANSP): 3.7/6.3/13.7/11.9/13.4/13.5/18.3/19.2, suman 100.0 exacto.
    $percentages = ['3.70', '6.30', '13.70', '11.90', '13.40', '13.50', '18.30', '19.20'];
    $costCenters = collect($percentages)->map(fn ($p, $i) => CostCenter::factory()->create([
        'company_id' => $company->id, 'code' => "CC-{$i}",
    ]));
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'TRANSP']);
    foreach ($costCenters as $i => $cc) {
        $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => $percentages[$i], 'position' => $i + 1]);
    }

    $service = app(PostJournalService::class);

    $entry = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($gasto->id, $company->local_currency_id, debit: 333.33, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 333.33),
    ]);

    $gastoDetails = $entry->details->where('account_id', $gasto->id);

    expect($gastoDetails)->toHaveCount(8)
        ->and($gastoDetails->reduce(fn ($c, $d) => bcadd($c, (string) $d->debit_local, 2), '0.00'))->toEqual('333.33');
});

it('rechaza combinar norma de reparto con apertura de partida en la misma línea', function () {
    new JournalLineInput(1, 1, debit: 100, credit: 0, businessPartnerId: 1, costAllocationRuleId: 1, opensItem: true);
})->throws(InvalidArgumentException::class);

it('no asigna número de serie si no se indica ninguna', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    expect($entry->number_series_id)->toBeNull()
        ->and($entry->series_number)->toBeNull();
});

it('asigna el número de una serie manual cuando se indica, sin tocar el consecutivo interno', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $documentType->id,
        'range_from' => 100, 'range_to' => 200, 'next_number' => 100,
    ]);

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ], numberSeriesId: $series->id);

    expect($entry->number_series_id)->toBe($series->id)
        ->and($entry->series_number)->toBe(100)
        ->and($entry->document_number)->toBe(1) // el consecutivo interno sigue su propia cuenta
        ->and($series->fresh()->next_number)->toBe(101);
});

it('asigna números sucesivos de la misma serie en posteos consecutivos', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $documentType->id,
        'range_from' => 1, 'range_to' => 10, 'next_number' => 1,
    ]);
    $service = app(PostJournalService::class);

    $first = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 10, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 10),
    ], numberSeriesId: $series->id);

    $second = $service->post($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 20, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 20),
    ], numberSeriesId: $series->id);

    expect($first->series_number)->toBe(1)
        ->and($second->series_number)->toBe(2);
});

it('rechaza contabilizar contra una serie que ya agotó su rango', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $documentType->id,
        'range_from' => 1, 'range_to' => 5, 'next_number' => 6, // ya se usaron todos
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 10, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 10),
    ], numberSeriesId: $series->id);
})->throws(NumberSeriesExhaustedException::class);

it('rechaza contabilizar contra una serie inactiva', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $documentType->id, 'is_active' => false,
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 10, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 10),
    ], numberSeriesId: $series->id);
})->throws(NumberSeriesExhaustedException::class);

it('rechaza contabilizar contra una serie que pertenece a otro tipo de documento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $otherType = \App\Domains\Core\Models\DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'OTR']);
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $otherType->id,
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 10, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 10),
    ], numberSeriesId: $series->id);
})->throws(NumberSeriesExhaustedException::class);

it('rechaza contabilizar contra una norma de reparto que no existe en la compañía', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: 999999),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(CostCenterNotPostableException::class);

it('rechaza contabilizar contra una norma de reparto inactiva', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-01']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA1', 'is_active' => false]);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(CostCenterNotPostableException::class);

it('rechaza contabilizar contra una norma de reparto fuera de su vigencia en la fecha del asiento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-01']);
    $rule = CostAllocationRule::factory()->create([
        'company_id' => $company->id, 'code' => 'NORMA1',
        'valid_from' => '2026-06-01', 'valid_until' => '2026-06-30',
    ]);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    // el asiento es de enero, la norma solo es válida en junio
    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(CostCenterNotPostableException::class);

it('rechaza contabilizar contra una norma de reparto que referencia un centro de costo inactivo', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-01', 'is_active' => false]);
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
})->throws(CostCenterNotPostableException::class);

it('permite contabilizar contra una norma de reparto dentro de su vigencia en la fecha del asiento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-01']);
    $rule = CostAllocationRule::factory()->create([
        'company_id' => $company->id, 'code' => 'NORMA1',
        'valid_from' => '2026-01-01', 'valid_until' => '2026-01-31',
    ]);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    expect($entry->details->firstWhere('account_id', $cash->id)->cost_center_id)->toBe($cc->id);
});

// --- Borradores ("guardar como preliminar") -------------------------------

it('guarda un borrador con líneas incompletas: sin monto y sin cuadrar', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->saveDraft(
        $company,
        $documentType,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 0, allowZeroAmount: true),
        ],
        'Todavía falta terminar este asiento',
    );

    expect($entry->status)->toBe('draft')
        ->and($entry->document_number)->toBeNull()
        ->and($entry->fiscal_period_id)->toBeNull()
        ->and($entry->details)->toHaveCount(2);

    $pending = $entry->details->firstWhere('account_id', $capital->id);
    expect($pending->debit_local)->toEqual('0.00')
        ->and($pending->credit_local)->toEqual('0.00');
});

it('un borrador se puede guardar en una fecha sin período fiscal configurado', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->saveDraft(
        $company,
        $documentType,
        new DateTime('2027-06-01'), // no hay período fiscal configurado para este año
        new DateTime('2027-06-01'),
        [new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0)],
    );

    expect($entry->status)->toBe('draft')
        ->and($entry->fiscal_period_id)->toBeNull();
});

it('un borrador puede referenciar una cuenta que no acepta movimientos: esa regla solo aplica al contabilizar', function () {
    ['company' => $company, 'documentType' => $documentType] = contappFixture();
    $summary = ChartOfAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '1-01-01-01-000',
        'accepts_posting' => false,
    ]);

    $entry = app(PostJournalService::class)->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($summary->id, $company->local_currency_id, debit: 100, credit: 0),
    ]);

    expect($entry->status)->toBe('draft');
});

it('rechaza guardar un borrador con una cuenta que no existe en la compañía', function () {
    ['company' => $company, 'documentType' => $documentType] = contappFixture();
    $otherCompany = Company::factory()->create();
    $foreignAccount = ChartOfAccount::factory()->create(['company_id' => $otherCompany->id]);

    app(PostJournalService::class)->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($foreignAccount->id, $company->local_currency_id, debit: 100, credit: 0),
    ]);
})->throws(NonPostingAccountException::class);

it('editar un borrador existente reemplaza por completo sus líneas anteriores', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ]);

    $updated = $service->saveDraft(
        $company,
        $documentType,
        new DateTime('2026-01-16'),
        new DateTime('2026-01-16'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 200, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 200),
        ],
        'Ya completo',
        existingDraft: $draft,
    );

    expect($updated->id)->toBe($draft->id)
        ->and($updated->document_date->format('Y-m-d'))->toBe('2026-01-16')
        ->and($updated->description)->toBe('Ya completo')
        ->and($updated->details)->toHaveCount(2);
});

it('rechaza volver a guardar como borrador un asiento que ya fue contabilizado', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $posted = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $service->saveDraft($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 50, credit: 0),
    ], existingDraft: $posted);
})->throws(JournalEntryNotDraftException::class);

it('rechaza editar como borrador un asiento que pertenece a otra compañía', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $fixtureB = contappFixture();

    $draftB = app(PostJournalService::class)->saveDraft(
        $fixtureB['company'],
        $fixtureB['documentType'],
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [new JournalLineInput($fixtureB['cash']->id, $fixtureB['company']->local_currency_id, debit: 50, credit: 0)],
    );

    app(PostJournalService::class)->saveDraft($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 50, credit: 0),
    ], existingDraft: $draftB);
})->throws(JournalEntryNotDraftException::class);

// --- Contabilizar formalmente un borrador (post() con $draftToFinalize) ---

it('contabiliza formalmente un borrador: asigna numeración y período, y reemplaza sus líneas por los montos definitivos', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 0, allowZeroAmount: true),
    ], 'Falta terminar');

    expect($draft->status)->toBe('draft')
        ->and($draft->document_number)->toBeNull();

    $finalized = $service->post($company, $documentType, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 520, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 520),
    ], 'Completo', draftToFinalize: $draft);

    expect($finalized->id)->toBe($draft->id)
        ->and($finalized->status)->toBe('posted')
        ->and($finalized->document_number)->toBe(1)
        ->and($finalized->fiscal_period_id)->not->toBeNull()
        ->and($finalized->document_date->format('Y-m-d'))->toBe('2026-01-20')
        ->and($finalized->description)->toBe('Completo')
        ->and($finalized->details)->toHaveCount(2);

    $cashDetail = $finalized->details->firstWhere('account_id', $cash->id);
    expect($cashDetail->debit_local)->toEqual('520.00')
        ->and($cashDetail->debit_foreign)->toEqual('1.00');
});

it('conserva quién creó el borrador y registra por separado quién lo contabiliza', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $creator = User::factory()->create();
    $poster = User::factory()->create();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ], createdBy: $creator->id);

    $finalized = $service->post($company, $documentType, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ], createdBy: $poster->id, draftToFinalize: $draft);

    expect($finalized->created_by)->toBe($creator->id)
        ->and($finalized->posted_by)->toBe($poster->id);
});

it('rechaza contabilizar formalmente un asiento que ya no está en borrador', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $posted = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $service->post($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 50, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 50),
    ], draftToFinalize: $posted);
})->throws(JournalEntryNotDraftException::class);

it('rechaza contabilizar formalmente un borrador que pertenece a otra compañía', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $fixtureB = contappFixture();

    $draftB = app(PostJournalService::class)->saveDraft(
        $fixtureB['company'],
        $fixtureB['documentType'],
        new DateTime('2026-01-15'),
        new DateTime('2026-01-15'),
        [new JournalLineInput($fixtureB['cash']->id, $fixtureB['company']->local_currency_id, debit: 50, credit: 0)],
    );

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 50, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 50),
    ], draftToFinalize: $draftB);
})->throws(JournalEntryNotDraftException::class);

it('un borrador que no cuadra sigue rechazándose al contabilizarlo formalmente', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ], 'Incompleto');

    $service->post($company, $documentType, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 50),
    ], draftToFinalize: $draft);
})->throws(UnbalancedJournalEntryException::class);

it('tras un intento fallido de contabilizar, el borrador sigue intacto', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ], 'Incompleto');

    try {
        $service->post($company, $documentType, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 50),
        ], draftToFinalize: $draft);
    } catch (UnbalancedJournalEntryException) {
        // esperado: el intento debe fallar sin tocar el borrador original
    }

    $fresh = JournalEntry::withoutGlobalScope(CompanyScope::class)->find($draft->id);

    expect($fresh->status)->toBe('draft')
        ->and($fresh->details)->toHaveCount(1)
        ->and($fresh->description)->toBe('Incompleto');
});

// --- Tipo de cambio manual (anula el automático solo para este asiento) ---

it('sin tipo de cambio manual, usa el automático de exchange_rates según la fecha (520.00 del fixture)', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 520, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 520),
    ]);

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('520.000000')
        ->and($cashDetail->debit_foreign)->toEqual('1.00');
});

it('un tipo de cambio manual anula el automático para todas las líneas del asiento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    // El fixture ya tiene un TC automático de 520.00 para esta fecha — se
    // pide explícitamente uno distinto (500.00) y debe ganar el manual.
    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 500, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 500),
    ], manualExchangeRate: '500.00');

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);
    $capitalDetail = $entry->details->firstWhere('account_id', $capital->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('500.000000')
        ->and($cashDetail->debit_foreign)->toEqual('1.00')
        ->and($capitalDetail->exchange_rate_lc_fc)->toEqual('500.000000')
        ->and($capitalDetail->credit_foreign)->toEqual('1.00');
});

it('el tipo de cambio manual también se aplica a una línea digitada en moneda extranjera', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->foreign_currency_id, debit: 2, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 1000),
    ], manualExchangeRate: '500.00');

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('500.000000')
        ->and($cashDetail->debit_local)->toEqual('1000.00');
});

it('el tipo de cambio manual no afecta el factor a moneda de sistema cuando extranjera = sistema (sigue en 1.00)', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    expect($company->foreign_currency_id)->toBe($company->system_currency_id); // supuesto del fixture, ver CompanyFactory

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 500, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 500),
    ], manualExchangeRate: '500.00');

    $cashDetail = $entry->details->firstWhere('account_id', $cash->id);

    expect($cashDetail->exchange_rate_fc_sc)->toEqual('1.000000')
        ->and($cashDetail->debit_system)->toEqual($cashDetail->debit_foreign);
});

it('el tipo de cambio manual también se puede usar al contabilizar formalmente un borrador', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ]);

    $finalized = $service->post($company, $documentType, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 600, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 600),
    ], draftToFinalize: $draft, manualExchangeRate: '600.00');

    $cashDetail = $finalized->details->firstWhere('account_id', $cash->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('600.000000')
        ->and($cashDetail->debit_foreign)->toEqual('1.00');
});

// --- Vigencia de tarifas de IVA al contabilizar (attachTax) ---------------

it('rechaza contabilizar con una tarifa de IVA que ya venció para la fecha del asiento', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $ivaSoportado = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-04-01-001']);
    $taxRate = TaxRate::factory()->create([
        'code' => 'IVA-13', 'percentage' => '13.00',
        'effective_from' => '2019-07-01', 'effective_to' => '2025-12-31',
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 1130),
        new JournalLineInput(
            $ivaSoportado->id, $company->local_currency_id, debit: 130, credit: 0,
            taxRateId: $taxRate->id, taxableBase: 1000,
        ),
    ]);
})->throws(TaxRateNotEffectiveException::class);

it('rechaza contabilizar con una tarifa de IVA que todavía no empieza a regir', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $ivaSoportado = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-04-01-001']);
    $taxRate = TaxRate::factory()->create([
        'code' => 'IVA-NUEVA', 'percentage' => '13.00',
        'effective_from' => '2026-02-01', 'effective_to' => null,
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 1130),
        new JournalLineInput(
            $ivaSoportado->id, $company->local_currency_id, debit: 130, credit: 0,
            taxRateId: $taxRate->id, taxableBase: 1000,
        ),
    ]);
})->throws(TaxRateNotEffectiveException::class);

it('permite contabilizar con una tarifa vigente justo en los límites de su rango', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $ivaSoportado = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-04-01-001']);
    $taxRate = TaxRate::factory()->create([
        'code' => 'IVA-13', 'percentage' => '13.00',
        'effective_from' => '2026-01-15', 'effective_to' => '2026-01-15',
    ]);

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 1130),
        new JournalLineInput(
            $ivaSoportado->id, $company->local_currency_id, debit: 130, credit: 0,
            taxRateId: $taxRate->id, taxableBase: 1000,
        ),
    ]);

    expect($entry->status)->toBe('posted');
});

// --- Clave numérica electrónica (Hacienda) ---------------------------------
// Por LÍNEA, no por asiento: un mismo asiento puede juntar varias facturas de
// compra a la vez (ver el test "varias facturas" más abajo), así que la clave
// vive en JournalLineInput/journal_details, no en JournalEntry.

it('guarda la clave electrónica de una línea al contabilizar', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $key = str_repeat('1', 50);

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, electronicKey: $key),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    expect($entry->details->firstWhere('account_id', $cash->id)->electronic_key)->toBe($key);
    expect($entry->details->firstWhere('account_id', $capital->id)->electronic_key)->toBeNull();
});

it('guarda la clave electrónica de una línea al guardar un borrador', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $key = str_repeat('2', 50);

    $draft = app(PostJournalService::class)->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, electronicKey: $key),
    ]);

    expect($draft->details->first()->electronic_key)->toBe($key);
});

it('un asiento sin clave electrónica la guarda en null (la mayoría de los documentos no la necesitan)', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    expect($entry->details->pluck('electronic_key')->filter())->toBeEmpty();
});

it('un mismo asiento puede juntar varias facturas de compra, cada una con su propia clave electrónica', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $expense1 = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '5-01-01-01-001', 'accepts_posting' => true]);
    $expense2 = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '5-01-01-01-002', 'accepts_posting' => true]);
    $key1 = str_repeat('1', 50);
    $key2 = str_repeat('2', 50);

    $entry = app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($expense1->id, $company->local_currency_id, debit: 100, credit: 0, electronicKey: $key1),
        new JournalLineInput($expense2->id, $company->local_currency_id, debit: 50, credit: 0, electronicKey: $key2),
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 150),
    ]);

    expect($entry->details->firstWhere('account_id', $expense1->id)->electronic_key)->toBe($key1);
    expect($entry->details->firstWhere('account_id', $expense2->id)->electronic_key)->toBe($key2);
    expect($entry->details->firstWhere('account_id', $cash->id)->electronic_key)->toBeNull();
});

// --- Tres fechas: documento (informativa), contabilización (rectora), vencimiento (encabezado + línea) ---

it('la fecha de contabilización, no la de documento, determina el período fiscal', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType, 'period' => $period] = contappFixture();

    // document_date cae en diciembre 2025, donde el fixture no configura
    // ningún período fiscal (solo crea enero 2026) — si document_date
    // todavía determinara el período, esto fallaría con
    // NoOpenFiscalPeriodException. posting_date sí cae en el período abierto,
    // así que debe ser la que decide.
    $entry = app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2025-12-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
        ]
    );

    expect($entry->document_date->format('Y-m-d'))->toBe('2025-12-15')
        ->and($entry->posting_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($entry->fiscal_period_id)->toBe($period->id);
});

it('la fecha de contabilización, no la de documento, determina el tipo de cambio aplicado', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    // El fixture ya deja un TC de 520 vigente desde el 2026-01-01; se agrega
    // uno más nuevo a partir del 2026-01-20.
    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-20',
        'rate' => '530.000000',
    ]);

    // document_date es anterior al TC nuevo (resolvería 520 si mandara);
    // posting_date es posterior (resuelve 530) — el TC guardado debe ser 530.
    $entry = app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-25'),
        [
            new JournalLineInput($cash->id, $company->foreign_currency_id, debit: 100, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 53000),
        ]
    );

    expect($entry->details->firstWhere('account_id', $cash->id)->exchange_rate_lc_fc)->toEqual('530.000000');
});

it('una fecha de vencimiento de encabezado respalda a las líneas que no traen la suya propia', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $entry = app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0, dueDate: '2026-03-01'),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
        dueDate: new DateTime('2026-02-01'),
    );

    expect($entry->due_date->format('Y-m-d'))->toBe('2026-02-01')
        ->and($entry->details->firstWhere('account_id', $cash->id)->due_date->format('Y-m-d'))->toBe('2026-03-01')
        ->and($entry->details->firstWhere('account_id', $capital->id)->due_date->format('Y-m-d'))->toBe('2026-02-01');
});

it('una línea que abre partida sin vencimiento propio hereda el de encabezado en la partida abierta', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);

    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput(
                $cxc->id, $company->local_currency_id, debit: 100, credit: 0,
                businessPartnerId: $partner->id, opensItem: true,
            ),
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 100),
        ],
        dueDate: new DateTime('2026-02-15'),
    );

    // Regresión: openBpOpenItem() leía $line->dueDate directo, que queda en
    // null cuando la línea no trae vencimiento propio y solo el encabezado
    // lo tiene — tiene que leer el ya resuelto en el detalle guardado.
    $openItem = BpOpenItem::sole();
    expect($openItem->due_date->format('Y-m-d'))->toBe('2026-02-15');
});

// --- Anulación (reverse()) — CLAUDE.md: "nada contabilizado se borra, se
// anula con asiento de reversión" ------------------------------------------

it('anula un asiento contabilizado con un espejo exacto y lo marca voided', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $original = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 520, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 520),
    ], 'Aporte de capital');

    $reversal = $service->reverse($company, $original, new DateTime('2026-01-20'));

    expect($original->fresh()->status)->toBe('voided')
        ->and($reversal->status)->toBe('posted')
        ->and($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->document_number)->toBe(2) // mismo tipo de documento, sigue el consecutivo
        ->and($reversal->details)->toHaveCount(2);

    $cashLine = $reversal->details->firstWhere('account_id', $cash->id);
    expect($cashLine->debit_local)->toEqual('0.00')
        ->and($cashLine->credit_local)->toEqual('520.00')
        ->and($cashLine->debit_foreign)->toEqual('0.00')
        ->and($cashLine->credit_foreign)->toEqual('1.00'); // exactamente el mismo TC del original, no uno recalculado
});

it('rechaza anular un borrador', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $draft = $service->saveDraft($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
    ]);

    $service->reverse($company, $draft, new DateTime('2026-01-20'));
})->throws(JournalEntryNotPostedException::class);

it('rechaza anular un asiento que ya fue anulado (queda voided, no posted)', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $original = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $service->reverse($company, $original, new DateTime('2026-01-20'));
    $service->reverse($company, $original->fresh(), new DateTime('2026-01-21'));
})->throws(JournalEntryNotPostedException::class);

it('anular una línea que abrió partida sin pagos aplicados cierra esa partida', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);
    $service = app(PostJournalService::class);

    $original = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 100, credit: 0, businessPartnerId: $partner->id, opensItem: true),
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
    $openItem = BpOpenItem::sole();

    $service->reverse($company, $original, new DateTime('2026-01-20'));

    expect($openItem->fresh()->status)->toBe('closed')
        ->and($openItem->fresh()->balance)->toEqual('0.00');
});

it('rechaza anular una línea cuya partida ya tiene pagos aplicados', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);
    $service = app(PostJournalService::class);

    $original = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 100, credit: 0, businessPartnerId: $partner->id, opensItem: true),
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
    $openItem = BpOpenItem::sole();

    $payment = $service->post($company, $documentType, new DateTime('2026-01-18'), new DateTime('2026-01-18'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 40, credit: 0),
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 0, credit: 40, businessPartnerId: $partner->id),
    ]);
    app(ApplyPaymentService::class)->apply($openItem, $payment, 40, new DateTime('2026-01-18'));

    $service->reverse($company, $original->fresh(), new DateTime('2026-01-20'));
})->throws(UnreversibleJournalEntryException::class);

it('anular un asiento de pago deshace sus aplicaciones y restablece el saldo de la partida original', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);
    $service = app(PostJournalService::class);

    $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 100, credit: 0, businessPartnerId: $partner->id, opensItem: true),
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
    $openItem = BpOpenItem::sole();

    $payment = $service->post($company, $documentType, new DateTime('2026-01-18'), new DateTime('2026-01-18'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 40, credit: 0),
        new JournalLineInput($cxc->id, $company->local_currency_id, debit: 0, credit: 40, businessPartnerId: $partner->id),
    ]);
    app(ApplyPaymentService::class)->apply($openItem, $payment, 40, new DateTime('2026-01-18'));
    expect($openItem->fresh()->balance)->toEqual('60.00')->and($openItem->fresh()->status)->toBe('partial');

    $service->reverse($company, $payment->fresh(), new DateTime('2026-01-20'));

    expect($openItem->fresh()->balance)->toEqual('100.00')
        ->and($openItem->fresh()->status)->toBe('open')
        ->and(BpPaymentApplication::where('open_item_id', $openItem->id)->count())->toBe(0);
});

it('rechaza anular una línea que ya forma parte de una reconciliación interna', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();
    $suspense = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-09-01-001']);
    $service = app(PostJournalService::class);

    $entry1 = $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($suspense->id, $company->local_currency_id, debit: 300, credit: 0),
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 300),
    ]);
    $entry2 = $service->post($company, $documentType, new DateTime('2026-01-16'), new DateTime('2026-01-16'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 300, credit: 0),
        new JournalLineInput($suspense->id, $company->local_currency_id, debit: 0, credit: 300),
    ]);
    $d1 = $entry1->details->firstWhere('account_id', $suspense->id);
    $d2 = $entry2->details->firstWhere('account_id', $suspense->id);
    // AccountReconciliationService espera contexto de request normal
    // (CurrentCompany ambiental) — este test, como el resto del archivo,
    // corre sin ese ambiente, así que hay que setearlo a mano para este paso.
    app(\App\Domains\Core\Support\CurrentCompany::class)->set($company);
    app(AccountReconciliationService::class)->reconcile($suspense, [
        ['journal_detail_id' => $d1->id, 'amount' => '300'],
        ['journal_detail_id' => $d2->id, 'amount' => '300'],
    ]);

    $service->reverse($company, $entry1->fresh(), new DateTime('2026-01-20'));
})->throws(UnreversibleJournalEntryException::class);

it('rechaza anular un asiento de otra compañía', function () {
    ['company' => $company, 'documentType' => $documentType] = contappFixture();
    $fixtureB = contappFixture();
    $service = app(PostJournalService::class);

    $originalB = $service->post(
        $fixtureB['company'], $fixtureB['documentType'], new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($fixtureB['cash']->id, $fixtureB['company']->local_currency_id, debit: 100, credit: 0),
            new JournalLineInput($fixtureB['capital']->id, $fixtureB['company']->local_currency_id, debit: 0, credit: 100),
        ],
    );

    $service->reverse($company, $originalB, new DateTime('2026-01-20'));
})->throws(\InvalidArgumentException::class);

// --- Protocolo de control de socio de negocio por tipo de documento
// (aplica/vencimiento/ambos/ninguno) — ver DocumentType::BP_LINE_REQUIREMENTS,
// PostJournalService::post() y ::applyToExistingOpenItem() ------------------

function bpControlFixture(): array
{
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();

    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);

    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id,
        'gl_account_id' => $cxc->id,
        'currency_id' => $company->local_currency_id,
    ]);

    // $documentType (código ADD, del fixture base) abre la partida sin
    // exigir nada — bp_line_requirement se configura aparte, por prueba,
    // sobre el tipo de documento que sí se pone a prueba en cada caso.
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'),
        [
            new JournalLineInput($cxc->id, $company->local_currency_id, debit: 1000, credit: 0, businessPartnerId: $client->id, opensItem: true),
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    return compact('company', 'cash', 'cxc', 'client', 'documentType')
        + ['openItem' => BpOpenItem::where('business_partner_id', $client->id)->sole()];
}

it('rechaza una línea que intenta abrir partida y aplicar a una partida existente a la vez', function () {
    new JournalLineInput(
        1, 1, debit: 100, credit: 0,
        businessPartnerId: 1, opensItem: true, applyToOpenItemId: 5,
    );
})->throws(\InvalidArgumentException::class);

it('rechaza una línea con socio sin vencimiento ni aplicación cuando el tipo de documento exige "due_date"', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'due_date']);

    app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 100, businessPartnerId: $fx['client']->id),
    ]);
})->throws(BpLineRequirementException::class);

it('permite contabilizar cuando la línea con socio abre partida y el tipo de documento exige "due_date"', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'due_date']);

    $entry = app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 100, businessPartnerId: $fx['client']->id, opensItem: true),
    ]);

    expect($entry->status)->toBe('posted');
});

it('rechaza una línea con socio sin aplicación cuando el tipo de documento exige "application"', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000, businessPartnerId: $fx['client']->id),
    ]);
})->throws(BpLineRequirementException::class);

it('aplica una línea a una partida existente dentro de la MISMA transacción de contabilización', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    $payment = app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput(
            $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000,
            businessPartnerId: $fx['client']->id, applyToOpenItemId: $fx['openItem']->id,
        ),
    ]);

    expect($payment->status)->toBe('posted');

    $openItem = $fx['openItem']->fresh();
    expect($openItem->balance)->toEqual('0.00')
        ->and($openItem->status)->toBe('closed');

    $application = BpPaymentApplication::where('open_item_id', $openItem->id)->sole();
    expect($application->payment_journal_entry_id)->toBe($payment->id)
        ->and($application->applied_amount)->toEqual('1000.00');
});

it('deja una partida parcial cuando la línea aplicada es menor al saldo', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput(
            $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 400,
            businessPartnerId: $fx['client']->id, applyToOpenItemId: $fx['openItem']->id,
        ),
    ]);

    $openItem = $fx['openItem']->fresh();
    expect($openItem->balance)->toEqual('600.00')->and($openItem->status)->toBe('partial');
});

it('rechaza y revierte TODO el asiento si la aplicación excede el saldo de la partida (atomicidad)', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    try {
        app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1500, credit: 0),
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1500,
                businessPartnerId: $fx['client']->id, applyToOpenItemId: $fx['openItem']->id,
            ),
        ]);
    } catch (OpenItemOverpaymentException) {
        // esperado: el intento debe fallar sin dejar rastro
    }

    // Ni el JournalEntry del intento de pago ni el consumo de su consecutivo
    // sobreviven: un pago nunca queda contabilizado sin aplicarse de verdad.
    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('document_type_id', $trb->id)->count())->toBe(0)
        ->and($fx['openItem']->fresh()->balance)->toEqual('1000.00')
        ->and($trb->fresh()->next_consecutive)->toBe(1);
});

it('rechaza aplicar una línea a una partida de OTRO socio de negocio', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);
    $otherClient = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $fx['cxc']->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);

    app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput(
            $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000,
            businessPartnerId: $otherClient->id, applyToOpenItemId: $fx['openItem']->id,
        ),
    ]);
})->throws(InvalidOpenItemException::class);

it('rechaza aplicar una línea a una partida de OTRA compañía', function () {
    $fx = bpControlFixture();
    $fxB = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput(
            $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000,
            businessPartnerId: $fx['client']->id, applyToOpenItemId: $fxB['openItem']->id,
        ),
    ]);
})->throws(InvalidOpenItemException::class);

it('permite contabilizar con "either" cuando la línea abre partida (sin aplicar)', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'either']);

    $entry = app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 200, credit: 0),
        new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 200, businessPartnerId: $fx['client']->id, opensItem: true),
    ]);

    expect($entry->status)->toBe('posted');
});

it('permite contabilizar con "either" cuando la línea aplica (sin abrir partida)', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'either']);

    $entry = app(PostJournalService::class)->post($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 300, credit: 0),
        new JournalLineInput(
            $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 300,
            businessPartnerId: $fx['client']->id, applyToOpenItemId: $fx['openItem']->id,
        ),
    ]);

    expect($entry->status)->toBe('posted');
});

it('un borrador nunca exige el protocolo de control de socio, aunque el tipo de documento lo pida', function () {
    $fx = bpControlFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    $draft = app(PostJournalService::class)->saveDraft($fx['company'], $trb, new DateTime('2026-01-20'), new DateTime('2026-01-20'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 100, businessPartnerId: $fx['client']->id),
    ]);

    expect($draft->status)->toBe('draft');
});
