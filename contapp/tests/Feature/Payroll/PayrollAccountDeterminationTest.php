<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Models\User;

/**
 * La determinación de cuentas de planilla: una pantalla, un guardado.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global y tomar uno de otro archivo ata este test a que ese archivo
 * se cargue también.
 */
function determinationFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'expense', 'accepts_posting' => true,
    ]);

    $liability = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'liability', 'accepts_posting' => true,
    ]);

    return compact('company', 'expense', 'liability');
}

/** @return array<string, mixed> */
function determinationPayload(array $f, array $overrides = []): array
{
    $expense = $f['expense']->id;
    $liability = $f['liability']->id;

    return array_merge([
        'settings' => [
            'salary_expense_account_id' => $expense,
            'net_payable_account_id' => $liability,
            'income_tax_payable_account_id' => $liability,
        ],
        'contributions' => PayrollContribution::orderBy('id')->get()
            ->map(fn (PayrollContribution $c) => [
                'id' => $c->id,
                // La obrera no lleva gasto: se le retiene al trabajador.
                'expense_account_id' => $c->payer === 'employer' ? $expense : null,
                'liability_account_id' => $liability,
            ])->all(),
        'provisions' => PayrollProvision::orderBy('id')->get()
            ->map(fn (PayrollProvision $p) => [
                'id' => $p->id,
                'expense_account_id' => $expense,
                'liability_account_id' => $liability,
            ])->all(),
        'concepts' => PayrollConcept::orderBy('id')->get()
            ->map(fn (PayrollConcept $c) => ['id' => $c->id, 'account_id' => null])->all(),
    ], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────

it('guarda de un golpe las cuentas de la configuración, las cargas y las provisiones', function () {
    $f = determinationFixture();

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f))
        ->assertSessionHasNoErrors();

    $settings = PayrollSetting::where('company_id', $f['company']->id)->firstOrFail();

    expect($settings->salary_expense_account_id)->toBe($f['expense']->id)
        ->and($settings->net_payable_account_id)->toBe($f['liability']->id);

    // Ninguna carga ni provisión vigente queda sin su pasivo.
    expect(PayrollContribution::whereNull('liability_account_id')->count())->toBe(0)
        ->and(PayrollProvision::whereNull('expense_account_id')->count())->toBe(0);

    // Y la obrera sigue sin cuenta de gasto, que es lo correcto.
    $obrera = PayrollContribution::where('code', 'SEM-OBR')->firstOrFail();
    expect($obrera->expense_account_id)->toBeNull()
        ->and($obrera->liability_account_id)->toBe($f['liability']->id);
});

it('crea la configuración si la compañía todavía no la tenía', function () {
    $f = determinationFixture();

    expect(PayrollSetting::where('company_id', $f['company']->id)->exists())->toBeFalse();

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f))
        ->assertSessionHasNoErrors();

    expect(PayrollSetting::where('company_id', $f['company']->id)->exists())->toBeTrue();
});

it('rechaza una cuenta de otra compañía', function () {
    $f = determinationFixture();

    $otherCompany = Company::factory()->create();
    $intruder = ChartOfAccount::factory()->create([
        'company_id' => $otherCompany->id, 'account_type' => 'expense', 'accepts_posting' => true,
    ]);

    // Es la prueba que de verdad importa de esta pantalla: un id ajeno
    // cruzaría el gasto de una empresa al catálogo de otra, y el asiento
    // saldría contra una cuenta que no existe en su compañía.
    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f, [
        'settings' => [
            'salary_expense_account_id' => $intruder->id,
            'net_payable_account_id' => $f['liability']->id,
            'income_tax_payable_account_id' => null,
        ],
    ]))->assertSessionHasErrors('settings.salary_expense_account_id');

    expect(PayrollSetting::where('company_id', $f['company']->id)->exists())->toBeFalse();
});

it('rechaza una cuenta que no acepta movimientos', function () {
    $f = determinationFixture();

    // Una cuenta de mayor (no hoja) no acepta movimientos: el asiento la
    // rechazaría al contabilizar, y es mejor decirlo al configurar.
    $parent = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'expense', 'accepts_posting' => false,
    ]);

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f, [
        'settings' => [
            'salary_expense_account_id' => $parent->id,
            'net_payable_account_id' => $f['liability']->id,
            'income_tax_payable_account_id' => null,
        ],
    ]))->assertSessionHasErrors('settings.salary_expense_account_id');
});

it('no guarda nada si una sola fila viene mal', function () {
    $f = determinationFixture();

    $otherCompany = Company::factory()->create();
    $intruder = ChartOfAccount::factory()->create([
        'company_id' => $otherCompany->id, 'account_type' => 'liability', 'accepts_posting' => true,
    ]);

    $payload = determinationPayload($f);
    // Se corrompe UNA fila de las trece.
    $payload['contributions'][3]['liability_account_id'] = $intruder->id;

    $this->put(route('payroll-settings.accounts.update'), $payload)->assertSessionHasErrors();

    // Dejar la mitad asignada produce una planilla que falla a mitad de
    // contabilizar, con parte del asiento ya armado.
    expect(PayrollContribution::whereNotNull('liability_account_id')->count())->toBe(0)
        ->and(PayrollSetting::where('company_id', $f['company']->id)->exists())->toBeFalse();
});

it('permite dejar en blanco una cuenta para volver a heredar', function () {
    $f = determinationFixture();

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f));

    $concept = PayrollConcept::where('code', 'PRESTAMO')->firstOrFail();

    $payload = determinationPayload($f);
    $payload['concepts'] = [['id' => $concept->id, 'account_id' => $f['liability']->id]];

    $this->put(route('payroll-settings.accounts.update'), $payload)->assertSessionHasNoErrors();
    expect($concept->fresh()->account_id)->toBe($f['liability']->id);

    // Y vaciarla la devuelve a heredar, en vez de quedar pegada para siempre.
    $payload['concepts'] = [['id' => $concept->id, 'account_id' => null]];

    $this->put(route('payroll-settings.accounts.update'), $payload)->assertSessionHasNoErrors();
    expect($concept->fresh()->account_id)->toBeNull();
});

it('guardar los parámetros no borra las cuentas ya asignadas', function () {
    $f = determinationFixture();

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f))
        ->assertSessionHasNoErrors();

    // El formulario de parámetros no manda cuentas. Antes las dos cosas
    // vivían en el mismo endpoint, y guardar un parámetro con el formulario a
    // medio cargar dejaba las cuentas en null sin que nadie lo pidiera: la
    // planilla se volvía incontabilizable por haber cambiado los días de
    // vacaciones.
    $this->put(route('payroll-settings.update'), [
        'vacation_days_per_month' => 1.25,
        'max_deduction_percentage' => 50,
    ])->assertSessionHasNoErrors();

    $settings = PayrollSetting::where('company_id', $f['company']->id)->firstOrFail();

    expect((float) $settings->vacation_days_per_month)->toBe(1.25)
        ->and($settings->salary_expense_account_id)->toBe($f['expense']->id)
        ->and($settings->net_payable_account_id)->toBe($f['liability']->id);
});

it('el endpoint de parámetros no puede tocar cuentas ni pasándolas a mano', function () {
    $f = determinationFixture();

    $this->put(route('payroll-settings.accounts.update'), determinationPayload($f));

    $this->put(route('payroll-settings.update'), [
        'vacation_days_per_month' => 1,
        'max_deduction_percentage' => 0,
        // Un cliente viejo, o alguien probando la API, podría seguir
        // mandándolas: se ignoran en vez de aplicarse.
        'salary_expense_account_id' => null,
        'net_payable_account_id' => null,
    ])->assertSessionHasNoErrors();

    $settings = PayrollSetting::where('company_id', $f['company']->id)->firstOrFail();

    expect($settings->salary_expense_account_id)->toBe($f['expense']->id)
        ->and($settings->net_payable_account_id)->toBe($f['liability']->id);
});

it('exige permiso de escritura sobre planillas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $this->actingAs($user)
        ->put(route('payroll-settings.accounts.update'), [])
        ->assertForbidden();
});
