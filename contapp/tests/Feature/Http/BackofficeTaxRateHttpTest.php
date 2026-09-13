<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

/**
 * store/update/destroy de indicadores de IVA viven detrás del guard
 * 'propietario' (panel de backoffice) — a diferencia de index() (solo
 * lectura, guard 'web', ver TaxRateHttpTest.php). Estos tests arman primero
 * un fixture de compañía normal (guard 'web') cuando hace falta un asiento
 * contabilizado real, y recién después autentican como Propietario (guard
 * 'propietario') para la acción de escritura en sí — ambos guards conviven
 * en la misma sesión de test sin pisarse.
 */
function taxAccountFixture(?Company $company = null): array
{
    if ($company === null) {
        ['company' => $company] = logInAsCompanyUser();
    }

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $expense = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '6-01-01-01-001']);
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);

    $taxRate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00', 'effective_from' => '2019-07-01']);

    $ivaSoportado = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $taxRate->id,
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'expense', 'cash', 'taxRate', 'ivaSoportado', 'add');
}

function postExpenseWithTax(array $fx): JournalEntry
{
    return app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($fx['expense']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 1130),
            new JournalLineInput(
                $fx['ivaSoportado']->id, $fx['company']->local_currency_id, debit: 130, credit: 0,
                taxRateId: $fx['taxRate']->id, taxableBase: 1000,
            ),
        ],
        'Gastos de papelería'
    );
}

it('un propietario puede crear una tarifa de IVA nueva', function () {
    loginAsPropietario();
    $type = TaxType::factory()->create(['code' => 'IVA']);

    $this->post(route('backoffice.tax-rates.store'), [
        'tax_type_id' => $type->id,
        'code' => 'IVA-1',
        'name' => 'IVA tarifa reducida 1%',
        'percentage' => '1.00',
        'effective_from' => '2020-01-01',
    ])->assertSessionHasNoErrors();

    expect(TaxRate::where('code', 'IVA-1')->sole()->percentage)->toEqual('1.00');
});

it('rechaza crear una tarifa de IVA a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();
    $type = TaxType::factory()->create(['code' => 'IVA']);

    $this->post(route('backoffice.tax-rates.store'), [
        'tax_type_id' => $type->id,
        'code' => 'IVA-1',
        'name' => 'IVA tarifa reducida 1%',
        'percentage' => '1.00',
        'effective_from' => '2020-01-01',
    ])->assertRedirect(route('backoffice.login'));

    expect(TaxRate::where('code', 'IVA-1')->exists())->toBeFalse();
});

it('un propietario puede editar libremente una tarifa que nunca se usó', function () {
    loginAsPropietario();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00']);

    $this->put(route('backoffice.tax-rates.update', $rate->id), [
        'tax_type_id' => $rate->tax_type_id,
        'code' => 'IVA-13',
        'name' => 'IVA general (corregido)',
        'percentage' => '14.00',
        'effective_from' => '2019-07-01',
    ])->assertSessionHasNoErrors();

    expect($rate->fresh()->percentage)->toEqual('14.00');
});

it('una tarifa ya usada en un asiento contabilizado solo admite cerrarle la vigencia, no reescribir el porcentaje', function () {
    $fx = taxAccountFixture();
    postExpenseWithTax($fx);
    loginAsPropietario();

    $rate = $fx['taxRate'];

    $this->put(route('backoffice.tax-rates.update', $rate->id), [
        'tax_type_id' => $rate->tax_type_id,
        'code' => 'CAMBIADO',
        'name' => 'Intento de reescribir',
        'percentage' => '99.00',
        'effective_from' => '2019-07-01',
        'effective_to' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $fresh = $rate->fresh();
    expect($fresh->percentage)->toEqual('13.00') // no se tocó
        ->and($fresh->code)->toBe($rate->code) // no se tocó
        ->and($fresh->effective_to->format('Y-m-d'))->toBe('2026-12-31'); // esto sí se permite
});

it('un propietario puede eliminar una tarifa que nunca se usó ni está vinculada a ninguna cuenta', function () {
    loginAsPropietario();
    $rate = TaxRate::factory()->create(['code' => 'IVA-1']);

    $this->delete(route('backoffice.tax-rates.destroy', $rate->id))->assertSessionHasNoErrors();

    expect(TaxRate::find($rate->id))->toBeNull();
});

it('rechaza eliminar una tarifa ya usada en un asiento contabilizado', function () {
    $fx = taxAccountFixture();
    postExpenseWithTax($fx);
    loginAsPropietario();

    $this->delete(route('backoffice.tax-rates.destroy', $fx['taxRate']->id))->assertSessionHasErrors('tax_rate');

    expect(TaxRate::find($fx['taxRate']->id))->not->toBeNull();
});

it('rechaza eliminar una tarifa vinculada a una cuenta contable, aunque nunca se haya usado en un asiento', function () {
    loginAsPropietario();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13']);
    ChartOfAccount::factory()->create(['tax_rate_id' => $rate->id]);

    $this->delete(route('backoffice.tax-rates.destroy', $rate->id))->assertSessionHasErrors('tax_rate');

    expect(TaxRate::find($rate->id))->not->toBeNull();
});

it('rechaza editar o eliminar una tarifa a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13']);

    $this->put(route('backoffice.tax-rates.update', $rate->id), ['effective_to' => '2026-12-31'])
        ->assertRedirect(route('backoffice.login'));
    $this->delete(route('backoffice.tax-rates.destroy', $rate->id))
        ->assertRedirect(route('backoffice.login'));
});

// --- Derecho a crédito fiscal ---------------------------------------------

it('crea un indicador de impuesto indicando si da derecho a crédito fiscal', function () {
    loginAsPropietario();
    $type = TaxType::factory()->create(['code' => 'IVA']);

    $this->post(route('backoffice.tax-rates.store'), [
        'tax_type_id' => $type->id,
        'code' => 'IVA-1',
        'name' => 'IVA tarifa reducida 1%',
        'percentage' => '1.00',
        'grants_fiscal_credit' => true,
        'fiscal_credit_note' => 'Tarifa reducida',
        'effective_from' => '2020-01-01',
    ])->assertSessionHasNoErrors();

    $rate = TaxRate::where('code', 'IVA-1')->sole();
    expect($rate->grants_fiscal_credit)->toBeTrue()
        ->and($rate->fiscal_credit_note)->toBe('Tarifa reducida');
});

it('un indicador sin marcar da derecho a crédito fiscal en false, sin nota', function () {
    loginAsPropietario();
    $type = TaxType::factory()->create(['code' => 'IVA']);

    $this->post(route('backoffice.tax-rates.store'), [
        'tax_type_id' => $type->id,
        'code' => 'IVA-EX',
        'name' => 'IVA exento',
        'percentage' => '0.00',
        'effective_from' => '2020-01-01',
    ])->assertSessionHasNoErrors();

    $rate = TaxRate::where('code', 'IVA-EX')->sole();
    expect($rate->grants_fiscal_credit)->toBeFalse()
        ->and($rate->fiscal_credit_note)->toBeNull();
});

it('un propietario puede editar el derecho a crédito fiscal de una tarifa que nunca se usó', function () {
    loginAsPropietario();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13', 'grants_fiscal_credit' => false, 'fiscal_credit_note' => null]);

    $this->put(route('backoffice.tax-rates.update', $rate->id), [
        'tax_type_id' => $rate->tax_type_id,
        'code' => 'IVA-13',
        'name' => $rate->name,
        'percentage' => '13.00',
        'grants_fiscal_credit' => true,
        'fiscal_credit_note' => 'Crédito general',
        'effective_from' => '2019-07-01',
    ])->assertSessionHasNoErrors();

    $fresh = $rate->fresh();
    expect($fresh->grants_fiscal_credit)->toBeTrue()
        ->and($fresh->fiscal_credit_note)->toBe('Crédito general');
});

it('una tarifa ya usada en un asiento contabilizado no permite cambiar su derecho a crédito fiscal, solo cerrar vigencia', function () {
    $fx = taxAccountFixture();
    $fx['taxRate']->update(['grants_fiscal_credit' => true, 'fiscal_credit_note' => 'Crédito pleno']);
    postExpenseWithTax($fx);
    loginAsPropietario();

    $rate = $fx['taxRate'];

    $this->put(route('backoffice.tax-rates.update', $rate->id), [
        'tax_type_id' => $rate->tax_type_id,
        'code' => $rate->code,
        'name' => $rate->name,
        'percentage' => '13.00',
        'grants_fiscal_credit' => false,
        'fiscal_credit_note' => 'Intento de cambio',
        'effective_from' => '2019-07-01',
        'effective_to' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $fresh = $rate->fresh();
    expect($fresh->grants_fiscal_credit)->toBeTrue() // no se tocó
        ->and($fresh->fiscal_credit_note)->toBe('Crédito pleno') // no se tocó
        ->and($fresh->effective_to->format('Y-m-d'))->toBe('2026-12-31'); // esto sí se permite
});
