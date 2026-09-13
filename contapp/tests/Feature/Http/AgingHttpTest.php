<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\DocumentType;

function setUpAgingCompany(\App\Domains\Core\Models\Company $company): array
{
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

    return compact('cxc', 'sales', 'client', 'documentType');
}

function postAgingOpenSale(\App\Domains\Core\Models\Company $company, ChartOfAccount $cxc, ChartOfAccount $sales, BusinessPartner $client, DocumentType $documentType, string $amount, string $dueDate): void
{
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($cxc->id, $client->currency_id, debit: $amount, credit: 0, businessPartnerId: $client->id, dueDate: $dueDate, opensItem: true),
            new JournalLineInput($sales->id, $client->currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('muestra la antigüedad de saldos con los buckets correctos', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpAgingCompany($company);

    postAgingOpenSale($company, $cxc, $sales, $client, $documentType, '200', '2026-02-15');

    $response = $this->get(route('reports.aging.index', ['as_of' => '2026-03-01']))->assertOk();
    $response->assertInertia(fn ($page) => $page
            ->component('Reports/Aging')
            ->where('result.bucket_labels.d_1_30', '1-30 días')
            ->where('result.groups.0.bucket_totals.d_1_30', '200.00')
            ->where('result.groups.0.rows.0.buckets.d_1_30', '200.00')
        );
});

it('acepta cortes de días personalizados y devuelve los buckets con esos cortes', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpAgingCompany($company);

    postAgingOpenSale($company, $cxc, $sales, $client, $documentType, '200', '2026-02-15'); // 14 días vencida

    $this->get(route('reports.aging.index', ['as_of' => '2026-03-01', 'buckets' => '15,45,90']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('result.bucket_labels.d_1_15')
            ->where('result.groups.0.rows.0.buckets.d_1_15', '200.00')
        );
});

it('tolera espacios alrededor de las comas en los cortes personalizados', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    setUpAgingCompany($company);

    $this->get(route('reports.aging.index', ['as_of' => '2026-03-01', 'buckets' => ' 15, 45 , 90 ']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('result.bucket_labels.d_1_15'));
});

it('rechaza un valor de cortes que no son números y comas', function () {
    logInAsCompanyUser();

    $this->get(route('reports.aging.index', ['as_of' => '2026-03-01', 'buckets' => 'treinta,sesenta']))
        ->assertSessionHasErrors('buckets');
});

it('exporta la antigüedad de saldos a XLSX', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.aging.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta la antigüedad de saldos a PDF', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.aging.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('aísla la antigüedad de saldos entre compañías', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpAgingCompany($company);
    postAgingOpenSale($company, $cxc, $sales, $client, $documentType, '200', '2026-02-15');

    $otherCompany = \App\Domains\Core\Models\Company::factory()->create();
    $other = setUpAgingCompany($otherCompany);
    postAgingOpenSale($otherCompany, $other['cxc'], $other['sales'], $other['client'], $other['documentType'], '900', '2026-02-15');

    $this->get(route('reports.aging.index', ['as_of' => '2026-03-01']))
        ->assertInertia(fn ($page) => $page->where('result.groups.0.bucket_totals.d_1_30', '200.00'));
});
