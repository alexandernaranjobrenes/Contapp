<?php

use App\Domains\Accounting\Exceptions\UnbalancedOpeningBalancesException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\OpeningBalanceLoadService;
use App\Domains\Core\Models\Company;

it('carga saldos iniciales balanceados', function () {
    $company = Company::factory()->create();
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    $rows = app(OpeningBalanceLoadService::class)->load($company, $fiscalYear, [
        ['account_id' => $cash->id, 'debit_local' => 5000, 'credit_local' => 0],
        ['account_id' => $capital->id, 'debit_local' => 0, 'credit_local' => 5000],
    ]);

    expect($rows)->toHaveCount(2);
});

it('rechaza saldos iniciales que no cuadran', function () {
    $company = Company::factory()->create();
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    app(OpeningBalanceLoadService::class)->load($company, $fiscalYear, [
        ['account_id' => $cash->id, 'debit_local' => 5000, 'credit_local' => 0],
        ['account_id' => $capital->id, 'debit_local' => 0, 'credit_local' => 4000],
    ]);
})->throws(UnbalancedOpeningBalancesException::class);
