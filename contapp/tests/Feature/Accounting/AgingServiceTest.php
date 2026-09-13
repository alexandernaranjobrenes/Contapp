<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\AgingService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function agingFixture(): array
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
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cxc', 'sales', 'client', 'documentType');
}

function postOpenSale(array $fx, BusinessPartner $partner, string $amount, string $dueDate): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput(
                $fx['cxc']->id, $partner->currency_id, debit: $amount, credit: 0,
                businessPartnerId: $partner->id, dueDate: $dueDate, opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $partner->currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('clasifica las partidas abiertas en los buckets correctos según días vencidos a la fecha de corte', function () {
    $fx = agingFixture();

    postOpenSale($fx, $fx['client'], '100', '2026-03-15'); // vigente (no vencida aún)
    postOpenSale($fx, $fx['client'], '200', '2026-02-15'); // 14 días vencida
    postOpenSale($fx, $fx['client'], '300', '2026-01-15'); // 45 días vencida
    postOpenSale($fx, $fx['client'], '400', '2025-12-15'); // 76 días vencida
    postOpenSale($fx, $fx['client'], '500', '2025-11-01'); // 120 días vencida

    $result = app(AgingService::class)->build($fx['company'], '2026-03-01');

    expect($result->groups)->toHaveCount(1);
    $row = $result->groups[0]->rows[0];

    expect($row->buckets['current'])->toEqual('100.00')
        ->and($row->buckets['d_1_30'])->toEqual('200.00')
        ->and($row->buckets['d_31_60'])->toEqual('300.00')
        ->and($row->buckets['d_61_90'])->toEqual('400.00')
        ->and($row->buckets['over'])->toEqual('500.00')
        ->and($row->total)->toEqual('1500.00')
        ->and($row->documents)->toHaveCount(5);

    // El detalle por documento (número/fecha/monto) que despliega la
    // pantalla al expandir un socio — cada partida abierta detrás del total.
    $doc = collect($row->documents)->firstWhere('balance', '300.00');
    expect($doc->dueDate)->toBe('2026-01-15')
        ->and($doc->originalAmount)->toEqual('300.00')
        ->and($doc->bucket)->toBe('d_31_60')
        ->and($doc->documentLabel)->toStartWith("{$fx['documentType']->code}-");
});

it('permite personalizar los cortes de días en vez del estándar 30/60/90', function () {
    $fx = agingFixture();

    postOpenSale($fx, $fx['client'], '100', '2026-02-15'); // 14 días vencida
    postOpenSale($fx, $fx['client'], '200', '2026-01-25'); // 35 días vencida
    postOpenSale($fx, $fx['client'], '300', '2025-10-01'); // 151 días vencida

    $result = app(AgingService::class)->build($fx['company'], '2026-03-01', 'both', '15,45,90,180');

    expect($result->bucketLabels)->toEqual([
        'current' => 'Vigente',
        'd_1_15' => '1-15 días',
        'd_16_45' => '16-45 días',
        'd_46_90' => '46-90 días',
        'd_91_180' => '91-180 días',
        'over' => '+180 días',
    ]);

    $row = $result->groups[0]->rows[0];
    expect($row->buckets['d_1_15'])->toEqual('100.00')
        ->and($row->buckets['d_16_45'])->toEqual('200.00')
        ->and($row->buckets['d_91_180'])->toEqual('300.00')
        ->and($row->buckets['d_46_90'])->toEqual('0.00');
});

it('cae al estándar 30/60/90 si el parámetro de cortes personalizados viene vacío o inválido', function () {
    $fx = agingFixture();
    postOpenSale($fx, $fx['client'], '100', '2026-02-15');

    $result = app(AgingService::class)->build($fx['company'], '2026-03-01', 'both', 'no-son-numeros');

    expect($result->bucketLabels)->toHaveKey('d_1_30');
});

it('agrupa los saldos por moneda en vez de sumarlos entre monedas distintas', function () {
    $fx = agingFixture();

    $usdClient = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->foreign_currency_id,
    ]);

    postOpenSale($fx, $fx['client'], '100', '2026-03-15');
    postOpenSale($fx, $usdClient, '50', '2026-03-15');

    $result = app(AgingService::class)->build($fx['company'], '2026-03-01');

    expect($result->groups)->toHaveCount(2);

    $currencies = collect($result->groups)->pluck('currencyCode')->sort()->values();
    expect($currencies->toArray())->toEqual(['CRC', 'USD']);
});

it('filtra por tipo de socio (clientes vs. proveedores)', function () {
    $fx = agingFixture();

    $supplierAccount = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '2-01-01-01-001']);
    $supplier = BusinessPartner::factory()->supplier()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $supplierAccount->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);

    postOpenSale($fx, $fx['client'], '100', '2026-03-15');
    postOpenSale($fx, $supplier, '75', '2026-03-15');

    $clientsOnly = app(AgingService::class)->build($fx['company'], '2026-03-01', 'client');
    $suppliersOnly = app(AgingService::class)->build($fx['company'], '2026-03-01', 'supplier');

    expect($clientsOnly->groups[0]->rows)->toHaveCount(1)
        ->and($clientsOnly->groups[0]->rows[0]->partnerCode)->toBe($fx['client']->code)
        ->and($suppliersOnly->groups[0]->rows)->toHaveCount(1)
        ->and($suppliersOnly->groups[0]->rows[0]->partnerCode)->toBe($supplier->code);
});

it('aísla la antigüedad de saldos entre compañías distintas', function () {
    $fxA = agingFixture();
    postOpenSale($fxA, $fxA['client'], '100', '2026-03-15');

    $fxB = agingFixture();
    postOpenSale($fxB, $fxB['client'], '900', '2026-03-15');

    $result = app(AgingService::class)->build($fxA['company'], '2026-03-01');

    expect($result->groups[0]->rows)->toHaveCount(1)
        ->and($result->groups[0]->rows[0]->total)->toEqual('100.00');
});
