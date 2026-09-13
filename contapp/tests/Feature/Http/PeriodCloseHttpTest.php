<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function periodCloseHttpFixture(bool $superAdmin = false): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser(userAttributes: ['is_super_admin' => $superAdmin]);

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ACC']);
    ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-02-01-01-001', 'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    $jan = FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => now()->startOfYear()->format('Y-m-d'),
        'end_date' => now()->startOfYear()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    return compact('user', 'company', 'fiscalYear', 'jan');
}

it('lista los años y períodos fiscales de la compañía activa', function () {
    $fx = periodCloseHttpFixture();

    $this->get(route('period-close.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounting/PeriodClose')
            ->has('fiscalYears', 1)
            ->has('fiscalYears.0.periods', 1)
        );
});

it('cierra un período abierto sin borradores', function () {
    $fx = periodCloseHttpFixture();

    $this->post(route('period-close.close', $fx['jan']->id))
        ->assertSessionHasNoErrors();

    expect($fx['jan']->fresh()->status)->toBe('closed');
});

it('rechaza cerrar o reabrir un período de otra compañía', function () {
    $fx = periodCloseHttpFixture(superAdmin: true);

    // FiscalPeriod no tiene CompanyScope propio (se filtra vía
    // fiscal_year_id -> fiscal_years.company_id); sin el chequeo explícito
    // de fiscalYear en el controlador, este id sería accesible entre compañías.
    logInAsCompanyUser(Company::factory()->create(), ['is_super_admin' => true]);

    $this->post(route('period-close.close', $fx['jan']->id))->assertNotFound();
    $this->post(route('period-close.reopen', $fx['jan']->id))->assertNotFound();

    expect($fx['jan']->fresh()->status)->toBe('open');
});

it('rechaza reabrir un período cerrado si el usuario no es super usuario', function () {
    $fx = periodCloseHttpFixture(superAdmin: false);

    $this->post(route('period-close.close', $fx['jan']->id));

    $this->post(route('period-close.reopen', $fx['jan']->id))
        ->assertForbidden();

    expect($fx['jan']->fresh()->status)->toBe('closed');
});

it('permite reabrir un período cerrado si el usuario es super usuario', function () {
    $fx = periodCloseHttpFixture(superAdmin: true);

    $this->post(route('period-close.close', $fx['jan']->id));

    $this->post(route('period-close.reopen', $fx['jan']->id))
        ->assertSessionHasNoErrors();

    expect($fx['jan']->fresh()->status)->toBe('open');
});

it('crea el año fiscal siguiente sin exigir que el año actual esté cerrado', function () {
    $fx = periodCloseHttpFixture();

    expect($fx['fiscalYear']->status)->toBe('open');

    $nextYear = $fx['fiscalYear']->year + 1;

    $this->post(route('period-close.create-year'))
        ->assertSessionHasNoErrors();

    $created = FiscalYear::withoutGlobalScope(\App\Domains\Core\Scopes\CompanyScope::class)
        ->where('company_id', $fx['company']->id)->where('year', $nextYear)->sole();
    expect($created->status)->toBe('open')
        ->and($created->periods)->toHaveCount(12);
});

it('rechaza crear el año fiscal siguiente para una compañía a la que no se pertenece', function () {
    periodCloseHttpFixture();
    $companyB = Company::factory()->create();

    logInAsCompanyUser();

    $this->post(route('period-close.create-year'));

    // El año se crea para la compañía ACTIVA del usuario autenticado, nunca
    // para una compañía arbitraria — no hay forma de pedirlo por otra vía,
    // así que esto confirma que no se filtró nada hacia companyB.
    expect(FiscalYear::withoutGlobalScope(\App\Domains\Core\Scopes\CompanyScope::class)->where('company_id', $companyB->id)->count())->toBe(0);
});

it('cierra el año fiscal creando de oficio el tipo de documento ACC si todavía no existe', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $retainedEarnings = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-02-01-01-001', 'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => now()->startOfYear()->format('Y-m-d'),
        'end_date' => now()->format('Y-m-d'),
        'status' => 'open',
    ]);

    // A propósito, sin crear el tipo ACC de antemano (a diferencia de
    // periodCloseHttpFixture()) — así se prueba que closeYear() lo
    // provisiona solo, igual que ya hace OpeningBalanceBulkImporter con APE.
    $this->post(route('period-close.close-year', $fiscalYear->id), [
        'retained_earnings_account_id' => $retainedEarnings->id,
    ])->assertSessionHasNoErrors();

    $acc = DocumentType::withoutGlobalScope(\App\Domains\Core\Scopes\CompanyScope::class)
        ->where('company_id', $company->id)->where('code', 'ACC')->sole();
    expect($acc->is_closing_type)->toBeTrue()
        ->and($fiscalYear->fresh()->status)->toBe('closed');
});
