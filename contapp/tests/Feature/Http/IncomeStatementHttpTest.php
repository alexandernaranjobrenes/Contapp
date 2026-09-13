<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\DocumentType;

function setUpIncomeStatementCompany(\App\Domains\Core\Models\Company $company): array
{
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
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('cash', 'sales', 'documentType');
}

function postIncomeStatementSale(\App\Domains\Core\Models\Company $company, ChartOfAccount $cash, ChartOfAccount $sales, DocumentType $documentType, string $date, string $amount): void
{
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($sales->id, $company->local_currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('muestra el estado de resultados con los totales correctos', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cash' => $cash, 'sales' => $sales, 'documentType' => $documentType] = setUpIncomeStatementCompany($company);

    postIncomeStatementSale($company, $cash, $sales, $documentType, '2026-01-15', '300');

    $this->get(route('reports.income-statement.index', ['from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/IncomeStatement')
            ->where('result.sales_total', '300.00')
            ->where('result.net_profit', '300.00')
        );
});

it('exporta el estado de resultados a XLSX', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.income-statement.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta el estado de resultados a PDF', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.income-statement.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('aísla el estado de resultados entre compañías', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cash' => $cash, 'sales' => $sales, 'documentType' => $documentType] = setUpIncomeStatementCompany($company);
    postIncomeStatementSale($company, $cash, $sales, $documentType, '2026-01-15', '300');

    $otherCompany = \App\Domains\Core\Models\Company::factory()->create();
    $other = setUpIncomeStatementCompany($otherCompany);
    postIncomeStatementSale($otherCompany, $other['cash'], $other['sales'], $other['documentType'], '2026-01-15', '900');

    $this->get(route('reports.income-statement.index', ['from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertInertia(fn ($page) => $page->where('result.sales_total', '300.00'));
});
