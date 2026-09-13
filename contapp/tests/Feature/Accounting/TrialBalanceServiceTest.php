<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Accounting\Services\TrialBalanceService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function trialBalanceFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-01-001', 'description_es' => 'Caja general',
    ]);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001', 'description_es' => 'Ventas',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $unused = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '5-01-01-01-001', 'description_es' => 'Gastos varios',
        'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'sales', 'unused', 'documentType');
}

function postCashSale(array $fx, string $date, string $amount): \App\Domains\Accounting\Models\JournalEntry
{
    return app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: $amount),
        ],
        'Venta de contado'
    );
}

it('calcula saldo inicial (antes de la fecha desde) por separado del movimiento del período', function () {
    $fx = trialBalanceFixture();
    postCashSale($fx, '2026-01-05', '500'); // antes del rango => saldo inicial
    postCashSale($fx, '2026-01-15', '300'); // dentro del rango => movimiento del período

    $result = app(TrialBalanceService::class)->build($fx['company'], '2026-01-10', '2026-01-31');

    $cashRow = collect($result->rows)->firstWhere('code', $fx['cash']->code);
    $salesRow = collect($result->rows)->firstWhere('code', $fx['sales']->code);

    expect($cashRow->openingBalance)->toEqual('500.00')
        ->and($cashRow->periodDebit)->toEqual('300.00')
        ->and($cashRow->periodCredit)->toEqual('0.00')
        ->and($cashRow->periodNet)->toEqual('300.00')
        ->and($cashRow->closingBalance)->toEqual('800.00')
        ->and($salesRow->openingBalance)->toEqual('500.00')
        ->and($salesRow->periodCredit)->toEqual('300.00')
        // Cuenta de naturaleza crédito (ventas): el neto del periodo también
        // respeta esa naturaleza (crédito - débito), no una resta aritmética
        // ciega — mismo criterio que ya usa el saldo inicial/final.
        ->and($salesRow->periodNet)->toEqual('300.00')
        ->and($salesRow->closingBalance)->toEqual('800.00');
});

it('el neto del periodo resta débito y crédito de la MISMA cuenta cuando hay movimiento en ambos lados', function () {
    $fx = trialBalanceFixture();
    postCashSale($fx, '2026-01-05', '500'); // débito a caja: +500

    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput($fx['unused']->id, $fx['company']->local_currency_id, debit: '200', credit: 0),
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: '200'),
        ],
        'Pago de gasto en efectivo'
    );

    $result = app(TrialBalanceService::class)->build($fx['company'], '2026-01-01', '2026-01-31');
    $cashRow = collect($result->rows)->firstWhere('code', $fx['cash']->code);

    expect($cashRow->periodDebit)->toEqual('500.00')
        ->and($cashRow->periodCredit)->toEqual('200.00')
        ->and($cashRow->periodNet)->toEqual('300.00');
});

it('cuadra los totales de débito y crédito del período (partida doble)', function () {
    $fx = trialBalanceFixture();
    postCashSale($fx, '2026-01-05', '500');
    postCashSale($fx, '2026-01-15', '300');

    $result = app(TrialBalanceService::class)->build($fx['company'], '2026-01-10', '2026-01-31');

    expect($result->totalDebit)->toEqual($result->totalCredit)
        ->and($result->totalDebit)->toEqual('300.00');
});

it('sin fecha desde, todo el historial cuenta como movimiento del período y el saldo inicial es cero', function () {
    $fx = trialBalanceFixture();
    postCashSale($fx, '2026-01-05', '500');
    postCashSale($fx, '2026-01-15', '300');

    $result = app(TrialBalanceService::class)->build($fx['company'], null, '2026-01-31');

    $cashRow = collect($result->rows)->firstWhere('code', $fx['cash']->code);

    expect($cashRow->openingBalance)->toEqual('0.00')
        ->and($cashRow->periodDebit)->toEqual('800.00')
        ->and($cashRow->closingBalance)->toEqual('800.00');
});

it('oculta cuentas sin movimiento por defecto, y las muestra si se pide explícitamente', function () {
    $fx = trialBalanceFixture();
    postCashSale($fx, '2026-01-15', '300');

    $hidden = app(TrialBalanceService::class)->build($fx['company'], '2026-01-01', '2026-01-31', hideZeroMovement: true);
    $shown = app(TrialBalanceService::class)->build($fx['company'], '2026-01-01', '2026-01-31', hideZeroMovement: false);

    expect(collect($hidden->rows)->pluck('code'))->not->toContain($fx['unused']->code)
        ->and(collect($shown->rows)->pluck('code'))->toContain($fx['unused']->code);
});

it('aísla el balance de comprobación entre compañías distintas', function () {
    $fxA = trialBalanceFixture();
    postCashSale($fxA, '2026-01-15', '300');

    $fxB = trialBalanceFixture();
    postCashSale($fxB, '2026-01-15', '900');

    $result = app(TrialBalanceService::class)->build($fxA['company'], '2026-01-01', '2026-01-31');

    $cashRow = collect($result->rows)->firstWhere('code', $fxA['cash']->code);

    expect($cashRow->periodDebit)->toEqual('300.00');
});

it('una cuenta mayor (accepts_posting=false) aparece sumando saldo inicial, débito, crédito y saldo final de sus hojas, sin duplicar los totales', function () {
    $fx = trialBalanceFixture();

    // "1-01" es padre de "1-01-01-01-001" ($cash) por prefijo de código.
    ChartOfAccount::factory()->nonPosting()->create([
        'company_id' => $fx['company']->id,
        'code' => '1-01',
        'description_es' => 'ACTIVO CIRCULANTE',
    ]);

    postCashSale($fx, '2026-01-05', '500'); // antes del rango => saldo inicial
    postCashSale($fx, '2026-01-15', '300'); // dentro del rango => movimiento del período

    $result = app(TrialBalanceService::class)->build($fx['company'], '2026-01-10', '2026-01-31');

    $header = collect($result->rows)->firstWhere('code', '1-01');

    expect($header)->not->toBeNull()
        ->and($header->isHeader)->toBeTrue()
        ->and($header->depth)->toBe(1)
        ->and($header->openingBalance)->toEqual('500.00')
        ->and($header->periodDebit)->toEqual('300.00')
        ->and($header->closingBalance)->toEqual('800.00')
        // Los totales de la partida doble siguen viniendo solo de cuentas
        // hoja — sumar también la línea de la cuenta mayor los duplicaría.
        ->and($result->totalDebit)->toEqual('300.00');
});
