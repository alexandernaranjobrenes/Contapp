<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

it('devuelve el mayor de una cuenta con saldo y movimientos', function () {
    $fx = journalHttpFixture();

    app(PostJournalService::class)->post($fx['company'], $fx['add'], now(), now(), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 250, credit: 0),
        new JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 250),
    ]);

    $this->getJson(route('ledger.show', ['dimension' => 'account', 'id' => $fx['cash']->id]))
        ->assertOk()
        ->assertJson([
            'owner_code' => $fx['cash']->code,
            'closing_balance' => '250.00',
        ])
        ->assertJsonCount(1, 'movements');
});

it('filtra el mayor por rango de fechas', function () {
    // Fechas fijas (no relativas a "hoy"), a propósito: journalHttpFixture()
    // abre el período del mes en curso, lo que hace frágil cualquier prueba
    // de rango de fechas que dependa de cuándo se corre la suite.
    ['company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01', 'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    $service = app(PostJournalService::class);

    $service->post($company, $add, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
    $service->post($company, $add, new DateTime('2026-01-25'), new DateTime('2026-01-25'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 400),
    ]);

    $this->getJson(route('ledger.show', ['dimension' => 'account', 'id' => $cash->id]).'?from=2026-01-20&to=2026-01-31')
        ->assertOk()
        ->assertJson(['opening_balance' => '100.00', 'closing_balance' => '500.00'])
        ->assertJsonCount(1, 'movements');
});

it('devuelve el mayor de un socio de negocio', function () {
    $fx = journalHttpFixture();

    // cuenta crédito-normal propia para el socio: $fx['capital'] queda con
    // normal_balance='debit' por default de fábrica, que no sirve para
    // probar que el mayor invierte el signo en una cuenta crédito-normal.
    $payable = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '2-01-01-01-001', 'normal_balance' => 'credit',
    ]);

    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $payable->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    app(PostJournalService::class)->post($fx['company'], $fx['add'], now(), now(), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 80, credit: 0),
        new JournalLineInput($payable->id, $fx['company']->local_currency_id, debit: 0, credit: 80, businessPartnerId: $partner->id),
    ]);

    $this->getJson(route('ledger.show', ['dimension' => 'business-partner', 'id' => $partner->id]))
        ->assertOk()
        ->assertJson(['owner_code' => 'C-001', 'closing_balance' => '80.00']);
});

it('devuelve el mayor de un centro de costo', function () {
    $fx = journalHttpFixture();
    $cc = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => '01']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $fx['company']->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    app(PostJournalService::class)->post($fx['company'], $fx['add'], now(), now(), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 60, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 60),
    ]);

    $this->getJson(route('ledger.show', ['dimension' => 'cost-center', 'id' => $cc->id]))
        ->assertOk()
        ->assertJson(['owner_code' => '01', 'closing_balance' => '60.00']);
});

it('rechaza una dimensión de mayor que no existe en la ruta', function () {
    logInAsCompanyUser();

    $this->get('/ledger/not-a-real-thing/1')->assertNotFound();
});

it('rechaza ver el mayor de una cuenta de otra compañía', function () {
    logInAsCompanyUser();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    $this->getJson(route('ledger.show', ['dimension' => 'account', 'id' => $accountB->id]))->assertNotFound();
});

it('rechaza ver el mayor de un socio de otra compañía', function () {
    logInAsCompanyUser();
    $companyB = Company::factory()->create();
    $glB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $partnerB = BusinessPartner::create([
        'company_id' => $companyB->id, 'code' => 'X-001', 'name' => 'Ajeno', 'type' => 'client',
        'gl_account_id' => $glB->id, 'currency_id' => $companyB->local_currency_id, 'status' => 'active',
    ]);

    $this->getJson(route('ledger.show', ['dimension' => 'business-partner', 'id' => $partnerB->id]))->assertNotFound();
});

it('rechaza ver el mayor de un centro de costo de otra compañía', function () {
    logInAsCompanyUser();
    $companyB = Company::factory()->create();
    $ccB = CostCenter::factory()->create(['company_id' => $companyB->id, 'code' => '01']);

    $this->getJson(route('ledger.show', ['dimension' => 'cost-center', 'id' => $ccB->id]))->assertNotFound();
});

it('exporta el mayor a xlsx', function () {
    $fx = journalHttpFixture();

    app(PostJournalService::class)->post($fx['company'], $fx['add'], now(), now(), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 10, credit: 0),
        new JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 10),
    ]);

    $response = $this->get(route('ledger.export', ['dimension' => 'account', 'id' => $fx['cash']->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});
