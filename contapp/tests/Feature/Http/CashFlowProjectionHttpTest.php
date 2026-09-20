<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function setUpCashFlowCompany(Company $company): array
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

function postCashFlowReceivable(Company $company, ChartOfAccount $cxc, ChartOfAccount $sales, BusinessPartner $client, DocumentType $documentType, string $amount, string $dueDate): void
{
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-01'), new DateTime('2026-01-01'),
        [
            new JournalLineInput($cxc->id, $client->currency_id, debit: $amount, credit: 0, businessPartnerId: $client->id, dueDate: $dueDate, opensItem: true),
            new JournalLineInput($sales->id, $client->currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('muestra la proyección de cobros y pagos con los buckets correctos', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpCashFlowCompany($company);

    postCashFlowReceivable($company, $cxc, $sales, $client, $documentType, '100', '2026-01-10');

    $this->get(route('reports.cash-flow-projection.index', ['as_of' => '2026-01-01']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/CashFlowProjection')
            ->where('result.collections.0.bucket_totals.d_0_15', '100.00')
            ->where('result.collections.0.rows.0.buckets.d_0_15', '100.00')
        );
});

it('acepta cortes de días personalizados en la proyección', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpCashFlowCompany($company);

    postCashFlowReceivable($company, $cxc, $sales, $client, $documentType, '100', '2026-01-10'); // 9 días

    $this->get(route('reports.cash-flow-projection.index', ['as_of' => '2026-01-01', 'buckets' => '10,40']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('result.bucket_labels.d_0_10')
            ->where('result.collections.0.rows.0.buckets.d_0_10', '100.00')
        );
});

it('exporta la proyección de cobros y pagos a XLSX', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.cash-flow-projection.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta la proyección de cobros y pagos a PDF', function () {
    logInAsCompanyUser();

    $response = $this->get(route('reports.cash-flow-projection.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('aísla la proyección de cobros y pagos entre compañías', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    ['cxc' => $cxc, 'sales' => $sales, 'client' => $client, 'documentType' => $documentType] = setUpCashFlowCompany($company);
    postCashFlowReceivable($company, $cxc, $sales, $client, $documentType, '100', '2026-01-10');

    $otherCompany = Company::factory()->create();
    $other = setUpCashFlowCompany($otherCompany);
    postCashFlowReceivable($otherCompany, $other['cxc'], $other['sales'], $other['client'], $other['documentType'], '900', '2026-01-10');

    $this->get(route('reports.cash-flow-projection.index', ['as_of' => '2026-01-01']))
        ->assertInertia(fn ($page) => $page->where('result.collections.0.bucket_totals.d_0_15', '100.00'));
});
