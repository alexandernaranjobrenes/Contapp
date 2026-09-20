<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function setUpBalanceSheetCompany(Company $company): array
{
    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-01-01-01-001',
        'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('cash', 'capital', 'documentType');
}

function postBalanceSheetContribution(Company $company, ChartOfAccount $cash, ChartOfAccount $capital, DocumentType $documentType, string $date, string $amount): void
{
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('muestra el balance general con la ecuación contable cuadrando', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = setUpBalanceSheetCompany($company);

    postBalanceSheetContribution($company, $cash, $capital, $documentType, '2026-01-15', '500');

    $this->get(route('reports.balance-sheet.index', ['as_of' => '2026-01-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/BalanceSheet')
            ->where('result.assets_total', '500.00')
            ->where('result.is_balanced', true)
        );
});

it('exporta el balance general a XLSX', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.balance-sheet.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta el balance general a PDF', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.balance-sheet.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('aísla el balance general entre compañías', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = setUpBalanceSheetCompany($company);
    postBalanceSheetContribution($company, $cash, $capital, $documentType, '2026-01-15', '500');

    $otherCompany = Company::factory()->create();
    $other = setUpBalanceSheetCompany($otherCompany);
    postBalanceSheetContribution($otherCompany, $other['cash'], $other['capital'], $other['documentType'], '2026-01-15', '9000');

    $this->get(route('reports.balance-sheet.index', ['as_of' => '2026-01-31']))
        ->assertInertia(fn ($page) => $page->where('result.assets_total', '500.00'));
});
