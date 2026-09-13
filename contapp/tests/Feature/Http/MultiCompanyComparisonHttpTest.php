<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Licensing\Models\License;
use App\Models\User;

function postComparisonContribution(Company $company): void
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
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($cash->id, $company->local_currency_id, debit: '1000', credit: 0),
            new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: '1000'),
        ],
    );
}

it('muestra solo las compañías del mismo grupo (misma licencia) a las que el usuario pertenece', function () {
    $license = License::factory()->create(['max_companies' => 5]);

    $companyA = Company::factory()->create(['license_id' => $license->id]);
    $companyB = Company::factory()->create(['license_id' => $license->id]);
    $companyC = Company::factory()->create(['license_id' => $license->id]); // misma licencia, usuario SIN acceso
    $companyD = Company::factory()->create(); // sin licencia, grupo distinto

    postComparisonContribution($companyA);
    postComparisonContribution($companyB);

    $user = User::factory()->create(['default_company_id' => $companyA->id]);
    $companyA->users()->attach($user->id, ['is_default' => true]);
    $companyB->users()->attach($user->id, ['is_default' => false]);
    grantAllModuleAccess($user, $companyA);

    $this->actingAs($user);

    $this->get(route('reports.multi-company-comparison.index', ['as_of' => '2026-01-31', 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/MultiCompanyComparison')
            ->has('result.rows', 2)
        );
});

it('sin licencia, el comparativo muestra solo la compañía activa', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    postComparisonContribution($company);

    $this->get(route('reports.multi-company-comparison.index', ['as_of' => '2026-01-31', 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertInertia(fn ($page) => $page->has('result.rows', 1));
});

it('exporta el comparativo de empresas a XLSX', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.multi-company-comparison.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta el comparativo de empresas a PDF', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.multi-company-comparison.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});
