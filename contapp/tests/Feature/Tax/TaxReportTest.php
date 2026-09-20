<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\InvalidTaxAmountException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\JournalDetailTax;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Services\TaxReportService;

function taxFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit', 'tax_classification' => 'sales',
    ]);
    $ivaDevengado = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-03-01-001',
        'account_type' => 'liability', 'normal_balance' => 'credit', 'tax_classification' => 'iva_devengado',
    ]);

    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);

    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    $taxRate = TaxRate::factory()->create();

    return compact('company', 'cxc', 'sales', 'ivaDevengado', 'client', 'fve', 'taxRate');
}

function postSaleWithTax(array $fx): JournalEntry
{
    return app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-05'), new DateTime('2026-01-05'),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1130, credit: 0,
                businessPartnerId: $fx['client']->id, dueDate: '2026-02-04', opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
            new JournalLineInput(
                $fx['ivaDevengado']->id, $fx['company']->local_currency_id, debit: 0, credit: 130,
                taxRateId: $fx['taxRate']->id, taxableBase: 1000,
            ),
        ],
        'Factura con IVA'
    );
}

it('enlaza la línea de impuesto a journal_detail_taxes con la base y el monto correctos', function () {
    $fx = taxFixture();
    postSaleWithTax($fx);

    $tax = JournalDetailTax::sole();

    expect($tax->taxable_base)->toEqual('1000.00')
        ->and($tax->tax_amount)->toEqual('130.00')
        ->and($tax->tax_rate_id)->toBe($fx['taxRate']->id);
});

it('rechaza un monto de impuesto que no corresponde a la tarifa configurada', function () {
    $fx = taxFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-05'), new DateTime('2026-01-05'),
        [
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 1999, credit: 0, businessPartnerId: $fx['client']->id, opensItem: true),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
            new JournalLineInput(
                $fx['ivaDevengado']->id, $fx['company']->local_currency_id, debit: 0, credit: 999,
                taxRateId: $fx['taxRate']->id, taxableBase: 1000,
            ),
        ],
    );
})->throws(InvalidTaxAmountException::class);

it('arma el resumen de IVA agrupado por clasificación de cuenta', function () {
    $fx = taxFixture();
    postSaleWithTax($fx);

    $summary = app(TaxReportService::class)->generate($fx['company'], new DateTime('2026-01-01'), new DateTime('2026-01-31'));

    expect($summary['iva_devengado']['base'])->toEqual('1000.00')
        ->and($summary['iva_devengado']['tax'])->toEqual('130.00')
        ->and($summary['iva_soportado']['tax'])->toEqual('0.00')
        ->and($summary['neto_a_pagar'])->toEqual('130.00');
});
