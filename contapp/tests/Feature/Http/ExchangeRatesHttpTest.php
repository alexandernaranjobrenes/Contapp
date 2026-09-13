<?php

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Core\Models\Company;

it('solo lista los tipos de cambio de la moneda extranjera de la compañía activa', function () {
    ['company' => $companyA] = logInAsCompanyUser();
    $companyB = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $companyA->id, 'currency_id' => $companyA->foreign_currency_id,
        'rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => '520.000000',
    ]);
    ExchangeRate::factory()->create([
        'company_id' => $companyB->id, 'currency_id' => $companyB->foreign_currency_id,
        'rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => '999.000000',
    ]);

    $this->get(route('exchange-rates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ExchangeRates/Index')
            ->has('rates', 1)
            ->where('rates.0.rate', '520.000000')
        );
});

it('crea un tipo de cambio manual', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('exchange-rates.store'), [
        'rate_date' => '2026-02-01',
        'rate_type' => 'reference',
        'rate' => 525.5,
    ])->assertSessionHasNoErrors();

    $rate = ExchangeRate::where('company_id', $company->id)->sole();

    expect((string) $rate->rate)->toEqual('525.500000')
        ->and($rate->source)->toBe('manual');
});

it('el alta manual actualiza en vez de duplicar cuando la fecha y el tipo ya existen', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('exchange-rates.store'), ['rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => 520]);
    $this->post(route('exchange-rates.store'), ['rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => 530]);

    expect(ExchangeRate::where('company_id', $company->id)->count())->toBe(1);
    expect((string) ExchangeRate::where('company_id', $company->id)->sole()->rate)->toEqual('530.000000');
});

it('el alta manual puede corregir incluso una fila ya bloqueada', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('exchange-rates.store'), [
        'rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => 520, 'is_locked' => true,
    ]);

    $this->post(route('exchange-rates.store'), [
        'rate_date' => '2026-02-01', 'rate_type' => 'reference', 'rate' => 540,
    ])->assertSessionHasNoErrors();

    $rate = ExchangeRate::where('company_id', $company->id)->sole();
    expect((string) $rate->rate)->toEqual('540.000000');
});

it('sincroniza contra el BCCR usando el cliente inyectado y muestra el resultado', function () {
    bindFakeBccrClient('527.123456');
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('exchange-rates.sync'), ['date' => '2026-02-01'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $rate = ExchangeRate::where('company_id', $company->id)->sole();
    expect($rate->source)->toBe('bccr_api')
        ->and((string) $rate->rate)->toEqual('527.123456');
});

it('reporta un error controlado cuando el BCCR no devuelve dato para la fecha', function () {
    bindFakeBccrClient(null);
    logInAsCompanyUser();

    $this->post(route('exchange-rates.sync'), ['date' => '2026-02-01'])
        ->assertSessionHasErrors('sync');
});

it('elimina un tipo de cambio de la compañía activa', function () {
    ['company' => $company] = logInAsCompanyUser();
    $rate = ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
    ]);

    $this->delete(route('exchange-rates.destroy', $rate->id))->assertSessionHasNoErrors();

    expect(ExchangeRate::find($rate->id))->toBeNull();
});

it('rechaza eliminar un tipo de cambio de otra compañía', function () {
    $companyB = Company::factory()->create();
    $rateB = ExchangeRate::factory()->create([
        'company_id' => $companyB->id, 'currency_id' => $companyB->foreign_currency_id,
    ]);

    logInAsCompanyUser();

    $this->delete(route('exchange-rates.destroy', $rateB->id))->assertNotFound();
});
