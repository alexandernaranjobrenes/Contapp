<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

/**
 * Contabiliza un gasto real con la tarifa propia $rate aplicada, para poder
 * probar el guard de "indicador ya usado" — mismo patrón que
 * BackofficeTaxRateHttpTest::taxAccountFixture()/postExpenseWithTax().
 */
function postExpenseWithOwnTax(Company $company, TaxRate $rate): void
{
    // PostJournalService::attachTax() busca la tarifa vía TaxRate::findOrFail()
    // (GlobalOrOwnCompanyScope), y este helper llama al servicio directo, sin
    // pasar por el middleware SetCurrentCompany de una request real — hay que
    // setearlo a mano, igual que el resto de este archivo hace con logInAsCompanyUser().
    app(\App\Domains\Core\Support\CurrentCompany::class)->set($company);

    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01', 'rate' => '520.000000',
    ]);

    $expense = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '6-01-01-01-001']);
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $taxAccount = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $rate->id,
    ]);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    app(PostJournalService::class)->post(
        $company, $add, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($expense->id, $company->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 1010),
            new JournalLineInput($taxAccount->id, $company->local_currency_id, debit: 10, credit: 0, taxRateId: $rate->id, taxableBase: 1000),
        ],
        'Gasto municipal'
    );
}

it('el índice de tarifas es visible para cualquier usuario autenticado, con la bandera in_use', function () {
    logInAsCompanyUser();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13']);

    $this->get(route('tax-rates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tax/Rates')
            ->has('rates', 1)
            ->where('rates.0.code', 'IVA-13')
            ->where('rates.0.in_use', false)
        );
});

it('el índice expone el derecho a crédito fiscal de cada indicador', function () {
    logInAsCompanyUser();
    TaxRate::factory()->create([
        'code' => 'IVA-13', 'grants_fiscal_credit' => true, 'fiscal_credit_note' => 'Crédito pleno',
    ]);

    $this->get(route('tax-rates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tax/Rates')
            ->where('rates.0.grants_fiscal_credit', true)
            ->where('rates.0.fiscal_credit_note', 'Crédito pleno')
        );
});

it('una compañía crea un indicador propio bajo un tipo de impuesto ya existente', function () {
    ['company' => $company] = logInAsCompanyUser();
    $ivaType = TaxType::factory()->create(['code' => 'IVA']);

    $this->post(route('tax-rates.store'), [
        'tax_type_id' => $ivaType->id,
        'code' => 'IVA-13-B',
        'name' => 'IVA tarifa general 13% (ajuste interno)',
        'percentage' => '13.00',
        'effective_from' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    $rate = TaxRate::sole();
    expect($rate->company_id)->toBe($company->id)
        ->and($rate->tax_type_id)->toBe($ivaType->id);
});

it('una compañía crea un indicador propio con un tipo de impuesto nuevo, también propio', function () {
    logInAsCompanyUser();

    $this->post(route('tax-rates.store'), [
        'new_tax_type_code' => 'MUNICIPAL',
        'new_tax_type_name' => 'Impuesto Municipal',
        'code' => 'MUNI-1',
        'name' => 'Impuesto municipal 1%',
        'percentage' => '1.00',
        'effective_from' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    $type = TaxType::where('code', 'MUNICIPAL')->sole();
    $rate = TaxRate::where('code', 'MUNI-1')->sole();

    expect($type->company_id)->not->toBeNull()
        ->and($rate->company_id)->toBe($type->company_id)
        ->and($rate->tax_type_id)->toBe($type->id);
});

it('los indicadores propios de una compañía son invisibles para otra', function () {
    ['company' => $companyA] = logInAsCompanyUser();
    $this->post(route('tax-rates.store'), [
        'new_tax_type_code' => 'MUNICIPAL', 'new_tax_type_name' => 'Impuesto Municipal',
        'code' => 'MUNI-1', 'name' => 'Impuesto municipal 1%', 'percentage' => '1.00', 'effective_from' => '2026-01-01',
    ]);

    logInAsCompanyUser(Company::factory()->create());

    $this->get(route('tax-rates.index'))
        ->assertInertia(fn ($page) => $page->has('rates', 0));
});

it('el índice combina el catálogo nacional y los indicadores propios, marcando cuál es cuál', function () {
    logInAsCompanyUser();
    TaxRate::factory()->create(['code' => 'IVA-13']);

    $this->post(route('tax-rates.store'), [
        'new_tax_type_code' => 'MUNICIPAL', 'new_tax_type_name' => 'Impuesto Municipal',
        'code' => 'MUNI-1', 'name' => 'Impuesto municipal 1%', 'percentage' => '1.00', 'effective_from' => '2026-01-01',
    ]);

    $this->get(route('tax-rates.index'))
        ->assertInertia(fn ($page) => $page
            ->has('rates', 2)
            ->where('rates', fn ($rates) => collect($rates)->firstWhere('code', 'IVA-13')['is_global'] === true
                && collect($rates)->firstWhere('code', 'MUNI-1')['is_global'] === false)
        );
});

it('una compañía edita su propio indicador mientras no esté en uso', function () {
    ['company' => $company] = logInAsCompanyUser();
    $type = TaxType::factory()->create(['company_id' => $company->id, 'code' => 'MUNICIPAL']);
    $rate = TaxRate::factory()->create([
        'company_id' => $company->id, 'tax_type_id' => $type->id, 'code' => 'MUNI-1', 'percentage' => '1.00',
    ]);

    $this->put(route('tax-rates.update', $rate->id), [
        'tax_type_id' => $type->id,
        'code' => 'MUNI-1',
        'name' => 'Impuesto municipal actualizado',
        'percentage' => '2.00',
        'effective_from' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    expect($rate->fresh()->percentage)->toEqual('2.00');
});

it('rechaza editar o eliminar un indicador nacional desde las rutas de compañía', function () {
    logInAsCompanyUser();
    $globalRate = TaxRate::factory()->create(['code' => 'IVA-13']);

    $this->put(route('tax-rates.update', $globalRate->id), [
        'tax_type_id' => $globalRate->tax_type_id, 'code' => 'IVA-13', 'name' => 'x', 'percentage' => '99', 'effective_from' => '2026-01-01',
    ])->assertNotFound();

    $this->delete(route('tax-rates.destroy', $globalRate->id))->assertNotFound();

    expect(TaxRate::find($globalRate->id))->not->toBeNull();
});

it('rechaza tocar el indicador propio de otra compañía desde las rutas de compañía', function () {
    $companyA = Company::factory()->create();
    $typeA = TaxType::factory()->create(['company_id' => $companyA->id, 'code' => 'MUNICIPAL']);
    $rateA = TaxRate::factory()->create(['company_id' => $companyA->id, 'tax_type_id' => $typeA->id, 'code' => 'MUNI-1']);

    logInAsCompanyUser();

    $this->put(route('tax-rates.update', $rateA->id), [
        'tax_type_id' => $typeA->id, 'code' => 'MUNI-1', 'name' => 'x', 'percentage' => '5', 'effective_from' => '2026-01-01',
    ])->assertNotFound();

    $this->delete(route('tax-rates.destroy', $rateA->id))->assertNotFound();
});

it('un indicador propio ya usado en un asiento solo admite cerrarle la vigencia', function () {
    ['company' => $company] = logInAsCompanyUser();
    $type = TaxType::factory()->create(['company_id' => $company->id, 'code' => 'MUNICIPAL']);
    $rate = TaxRate::factory()->create([
        'company_id' => $company->id, 'tax_type_id' => $type->id, 'code' => 'MUNI-1', 'percentage' => '1.00',
    ]);
    postExpenseWithOwnTax($company, $rate);

    $this->put(route('tax-rates.update', $rate->id), [
        'code' => 'CAMBIADO', 'name' => 'Intento de reescribir', 'percentage' => '99.00', 'effective_to' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $fresh = $rate->fresh();
    expect($fresh->percentage)->toEqual('1.00')
        ->and($fresh->code)->toBe('MUNI-1')
        ->and($fresh->effective_to->format('Y-m-d'))->toBe('2026-12-31');
});

it('rechaza eliminar un indicador propio ya usado en un asiento o vinculado a una cuenta', function () {
    ['company' => $company] = logInAsCompanyUser();
    $type = TaxType::factory()->create(['company_id' => $company->id, 'code' => 'MUNICIPAL']);

    $usedRate = TaxRate::factory()->create([
        'company_id' => $company->id, 'tax_type_id' => $type->id, 'code' => 'MUNI-1', 'percentage' => '1.00',
    ]);
    postExpenseWithOwnTax($company, $usedRate);
    $this->delete(route('tax-rates.destroy', $usedRate->id))->assertSessionHasErrors('tax_rate');

    $linkedRate = TaxRate::factory()->create(['company_id' => $company->id, 'tax_type_id' => $type->id, 'code' => 'MUNI-2']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'tax_rate_id' => $linkedRate->id]);
    $this->delete(route('tax-rates.destroy', $linkedRate->id))->assertSessionHasErrors('tax_rate');

    expect(TaxRate::find($usedRate->id))->not->toBeNull()
        ->and(TaxRate::find($linkedRate->id))->not->toBeNull();
});
