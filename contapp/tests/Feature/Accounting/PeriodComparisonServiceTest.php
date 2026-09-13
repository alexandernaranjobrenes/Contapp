<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PeriodComparisonService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

it('cruza balance general y estado de resultados de dos periodos, calculando la variación por cuenta', function () {
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 2,
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'open',
    ]);

    $post = fn (string $date, string $amount) => app(PostJournalService::class)->post(
        $company, $documentType, new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($sales->id, $company->local_currency_id, debit: 0, credit: $amount),
        ],
        'Venta de contado'
    );

    $post('2026-01-10', '500'); // periodo 1
    $post('2026-02-10', '800'); // periodo 2

    $result = app(PeriodComparisonService::class)->build(
        $company, '2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28'
    );

    // Balance general: activo (caja) acumulado a cada fecha de corte.
    expect($result->assetsTotal->period1)->toEqual('500.00')
        ->and($result->assetsTotal->period2)->toEqual('1300.00') // acumulado, no solo el movimiento del periodo 2
        ->and($result->assetsTotal->variance)->toEqual('800.00');

    // Estado de resultados: solo la actividad PROPIA de cada periodo, no acumulada.
    expect($result->salesTotal->period1)->toEqual('500.00')
        ->and($result->salesTotal->period2)->toEqual('800.00')
        ->and($result->salesTotal->variance)->toEqual('300.00')
        ->and($result->salesTotal->variancePercent)->toEqual('60.00');

    $salesLine = collect($result->sales)->firstWhere('code', $sales->code);
    expect($salesLine->amounts->period1)->toEqual('500.00')
        ->and($salesLine->amounts->period2)->toEqual('800.00');
});

it('deja la variación porcentual en null cuando el periodo base está en cero, en vez de dividir entre cero', function () {
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 2,
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'open',
    ]);

    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-02-10'), new DateTime('2026-02-10'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: '800', credit: 0),
            new JournalLineInput($sales->id, $company->local_currency_id, debit: 0, credit: '800'),
        ],
        'Venta de contado'
    );

    $result = app(PeriodComparisonService::class)->build(
        $company, '2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28'
    );

    expect($result->salesTotal->period1)->toEqual('0.00')
        ->and($result->salesTotal->period2)->toEqual('800.00')
        ->and($result->salesTotal->variancePercent)->toBeNull();
});
