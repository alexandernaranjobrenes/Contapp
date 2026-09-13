<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\IncomeStatementService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function incomeStatementFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001', 'description_es' => 'Ventas',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $costOfSales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '5-01-01-01-001', 'description_es' => 'Costo de ventas',
        'account_type' => 'cost_of_sales', 'normal_balance' => 'debit',
    ]);
    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '6-01-01-01-001', 'description_es' => 'Gastos administrativos',
        'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);
    $otherIncome = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '7-01-01-01-001', 'description_es' => 'Ingresos por intereses',
        'account_type' => 'other_income', 'normal_balance' => 'credit',
    ]);
    $otherExpense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '8-01-01-01-001', 'description_es' => 'Gastos financieros',
        'account_type' => 'other_expense', 'normal_balance' => 'debit',
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'sales', 'costOfSales', 'expense', 'otherIncome', 'otherExpense', 'documentType');
}

function post2(array $fx, \App\Domains\Accounting\Models\ChartOfAccount $debitAccount, \App\Domains\Accounting\Models\ChartOfAccount $creditAccount, string $amount, string $date = '2026-01-15'): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($debitAccount->id, $fx['company']->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($creditAccount->id, $fx['company']->local_currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('calcula ventas, costo de ventas, gastos, otros ingresos/gastos y las utilidades intermedias', function () {
    $fx = incomeStatementFixture();

    post2($fx, $fx['cash'], $fx['sales'], '1000');
    post2($fx, $fx['costOfSales'], $fx['cash'], '400');
    post2($fx, $fx['expense'], $fx['cash'], '300');
    post2($fx, $fx['cash'], $fx['otherIncome'], '50');
    post2($fx, $fx['otherExpense'], $fx['cash'], '20');

    $result = app(IncomeStatementService::class)->build($fx['company'], '2026-01-01', '2026-01-31');

    expect($result->salesTotal)->toEqual('1000.00')
        ->and($result->costOfSalesTotal)->toEqual('400.00')
        ->and($result->grossProfit)->toEqual('600.00')
        ->and($result->operatingExpensesTotal)->toEqual('300.00')
        ->and($result->operatingProfit)->toEqual('300.00')
        ->and($result->otherIncomeTotal)->toEqual('50.00')
        ->and($result->otherExpenseTotal)->toEqual('20.00')
        ->and($result->netProfit)->toEqual('330.00');
});

it('oculta cuentas sin movimiento por defecto, y las muestra si se pide explícitamente', function () {
    $fx = incomeStatementFixture();
    post2($fx, $fx['cash'], $fx['sales'], '1000');

    $hidden = app(IncomeStatementService::class)->build($fx['company'], '2026-01-01', '2026-01-31', hideZeroMovement: true);
    $shown = app(IncomeStatementService::class)->build($fx['company'], '2026-01-01', '2026-01-31', hideZeroMovement: false);

    expect(collect($hidden->costOfSales))->toHaveCount(0)
        ->and(collect($shown->costOfSales))->toHaveCount(1);
});

it('excluye el asiento de cierre anual del estado de resultados', function () {
    $fx = periodCloseFixture();
    $journalService = app(PostJournalService::class);

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
    ], 'Venta de contado');

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-02-05'), new DateTime('2026-02-05'), [
        new JournalLineInput($fx['expense']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 400),
    ], 'Gasto operativo');

    $closeService = app(\App\Domains\Accounting\Services\PeriodCloseService::class);
    $closeService->close($fx['company'], $fx['jan']);
    $closeService->closeYear($fx['company'], $fx['fiscalYear'], $fx['acc'], $fx['retainedEarnings']);

    $result = app(IncomeStatementService::class)->build($fx['company'], '2026-01-01', '2026-12-31');

    // Sin excluir el cierre, ventas y gastos quedarían en 0.00 (el asiento
    // de cierre los cancela) — con la exclusión deben mostrar la actividad
    // real del año, igual que ya verifica LedgerServiceTest para el mayor.
    expect($result->salesTotal)->toEqual('1000.00')
        ->and($result->operatingExpensesTotal)->toEqual('400.00')
        ->and($result->netProfit)->toEqual('600.00');
});

it('aísla el estado de resultados entre compañías distintas', function () {
    $fxA = incomeStatementFixture();
    post2($fxA, $fxA['cash'], $fxA['sales'], '1000');

    $fxB = incomeStatementFixture();
    post2($fxB, $fxB['cash'], $fxB['sales'], '9000');

    $result = app(IncomeStatementService::class)->build($fxA['company'], '2026-01-01', '2026-01-31');

    expect($result->salesTotal)->toEqual('1000.00');
});
