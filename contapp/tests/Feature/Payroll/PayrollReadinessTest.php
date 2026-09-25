<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PayrollTaxBracket;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PayrollReadinessChecker;

/**
 * La verificación previa: encontrar los problemas ANTES de calcular.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function readinessFixture(): array
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
    $documentType = DocumentType::factory()->create([
        'company_id' => $company->id, 'code' => 'PLA', 'origin_module' => 'planilla',
    ]);

    PayrollSetting::create([
        'company_id' => $company->id,
        'salary_expense_account_id' => $expense->id,
        'net_payable_account_id' => $liability->id,
        'income_tax_payable_account_id' => $liability->id,
        'document_type_id' => $documentType->id,
        'vacation_days_per_month' => '1',
        'max_deduction_percentage' => '0',
    ]);

    PayrollContribution::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id,
        'liability_account_id' => $liability->id,
    ]);
    PayrollProvision::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id,
        'liability_account_id' => $liability->id,
    ]);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'expense', 'liability', 'documentType', 'costCenter');
}

function readinessEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "R{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '3'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'ccss_number' => "SEG-{$n}",
        'first_name' => 'Luis',
        'last_name1' => "Listo{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '700000.00',
        'weekly_hours' => '48',
        'payment_method' => 'efectivo',
        'status' => 'active',
    ], $attributes));
}

function readinessPeriod(array $f): PayrollPeriod
{
    return PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => 7, 'name' => 'Julio 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'payment_date' => '2026-07-31',
        'status' => 'open',
    ]);
}

function checkReadiness(array $f, PayrollPeriod $period): array
{
    return app(PayrollReadinessChecker::class)->check($f['company'], $period);
}

/** @return string[] */
function findingTitles(array $result): array
{
    return collect($result['findings'])->pluck('title')->all();
}

// ─────────────────────────────────────────────────────────────────────────

it('no encuentra nada cuando todo está completo', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe(0)
        ->and($result['warnings'])->toBe(0);
});

it('marca como ERROR el empleado sin salario, porque su boleta saldría en cero', function () {
    $f = readinessFixture();
    readinessEmployee($f, ['base_salary' => '0.00']);

    $result = checkReadiness($f, readinessPeriod($f));

    // Un salario en cero no hace fallar el cálculo: produce una boleta en cero
    // que se pierde entre cincuenta. Por eso bloquea.
    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toBe(1)
        ->and(collect($result['findings'])->firstWhere('severity', 'error')['title'])
        ->toContain('no tiene salario');
});

it('marca como ERROR una jornada semanal en cero', function () {
    $f = readinessFixture();
    readinessEmployee($f, ['weekly_hours' => '0']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', findingTitles($result)))->toContain('jornada semanal');
});

it('marca como ADVERTENCIA lo que no arruina la planilla pero falla después', function () {
    $f = readinessFixture();
    readinessEmployee($f, [
        'cost_center_id' => null,
        'payment_method' => 'transferencia',
        'bank_account' => null,
        'ccss_number' => null,
    ]);

    $result = checkReadiness($f, readinessPeriod($f));

    // La planilla sale bien: lo que falla es el centro de costo del gasto, el
    // archivo del banco y el renglón de la Caja. Seguir es decisión del
    // usuario, así que NO bloquea.
    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe(0)
        ->and($result['warnings'])->toBe(3);

    $titles = implode(' ', findingTitles($result));

    expect($titles)->toContain('centro de costo')
        ->and($titles)->toContain('no tiene cuenta')
        ->and($titles)->toContain('número de asegurado');
});

it('avisa del empleado activo que queda fuera del período', function () {
    $f = readinessFixture();
    readinessEmployee($f);
    // Ingresa después de que el período cerró: es el caso que se descubre
    // cuando la persona reclama que no le pagaron.
    readinessEmployee($f, ['hire_date' => '2026-09-01']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeTrue()
        ->and(implode(' ', findingTitles($result)))->toContain('queda fuera del período');
});

it('marca como ERROR que ningún empleado entre en el período', function () {
    $f = readinessFixture();
    readinessEmployee($f, ['hire_date' => '2026-09-01']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', findingTitles($result)))->toContain('Ningún empleado entra');
});

it('detecta un hueco en la escala del impuesto', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    // Se corre el piso del tramo 3 para dejar un hueco sin gravar.
    PayrollTaxBracket::where('company_id', $f['company']->id)
        ->where('bracket_number', 3)
        ->update(['from_amount' => '1500000']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', findingTitles($result)))->toContain('Hueco entre los tramos 2 y 3');
});

it('detecta un traslape en la escala del impuesto', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    PayrollTaxBracket::where('company_id', $f['company']->id)
        ->where('bracket_number', 3)
        ->update(['from_amount' => '1000000']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', findingTitles($result)))->toContain('Traslape entre los tramos 2 y 3');
});

it('detecta que el último tramo tiene techo', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    PayrollTaxBracket::where('company_id', $f['company']->id)
        ->where('bracket_number', 5)
        ->update(['to_amount' => '9000000']);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', findingTitles($result)))->toContain('último tramo tiene techo');
});

it('avisa de las cargas y provisiones sin cuentas sin bloquear el cálculo', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    PayrollContribution::where('company_id', $f['company']->id)
        ->update(['expense_account_id' => null, 'liability_account_id' => null]);

    $result = checkReadiness($f, readinessPeriod($f));

    // Calcular está bien; contabilizar es lo que va a fallar.
    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->toBeGreaterThan(10);
});

it('cada hallazgo dice a dónde ir a corregirlo', function () {
    $f = readinessFixture();
    $employee = readinessEmployee($f, ['base_salary' => '0.00']);

    $result = checkReadiness($f, readinessPeriod($f));

    // Un aviso que no dice dónde se corrige obliga a buscarlo.
    $finding = collect($result['findings'])->firstWhere('severity', 'error');

    expect($finding['route'])->toBe('employees.show')
        ->and($finding['route_parameter'])->toBe($employee->id);
});

// ── Y lo que de verdad importa: que bloquee ──────────────────────────────

it('el botón de calcular rechaza la planilla cuando hay errores', function () {
    $f = readinessFixture();
    readinessEmployee($f, ['base_salary' => '0.00']);
    $period = readinessPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id))
        ->assertSessionHasErrors('payroll');

    // Y no dejó una planilla a medias.
    expect(PayrollEntry::where('payroll_period_id', $period->id)->count())->toBe(0)
        ->and($period->fresh()->status)->toBe('open');
});

it('calcula igual con advertencias, y lo dice', function () {
    $f = readinessFixture();
    readinessEmployee($f, ['cost_center_id' => null]);
    $period = readinessPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'advertencia'));

    expect($period->fresh()->status)->toBe('calculated');
});

it('la pantalla del período trae la verificación mientras se pueda recalcular', function () {
    $f = readinessFixture();
    readinessEmployee($f);
    $period = readinessPeriod($f);

    $this->get(route('payroll-periods.show', $period->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('readiness.ok', true));

    // Una vez contabilizada ya no tiene sentido verificar nada: no se puede
    // cambiar. Se manda null para que la pantalla no muestre el panel.
    $period->update(['status' => 'posted']);

    $this->get(route('payroll-periods.show', $period->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('readiness', null));
});

it('no se cuela entre compañías', function () {
    $f = readinessFixture();
    readinessEmployee($f);

    // Un empleado sin salario en OTRA compañía no puede bloquear esta planilla.
    $other = Company::factory()->create();
    Employee::create([
        'company_id' => $other->id, 'code' => 'AJENO',
        'identification_type' => 'cedula', 'identification_number' => '999999999',
        'first_name' => 'Ajeno', 'last_name1' => 'Ajeno',
        'hire_date' => '2020-01-01', 'salary_type' => 'mensual',
        'base_salary' => '0.00', 'weekly_hours' => '48', 'status' => 'active',
    ]);

    $result = checkReadiness($f, readinessPeriod($f));

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe(0);
});
