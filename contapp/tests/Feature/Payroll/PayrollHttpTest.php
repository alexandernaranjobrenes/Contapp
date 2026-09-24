<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Models\User;

/**
 * Las pruebas de la puerta de entrada: que cada pantalla cargue, que cada
 * acción exija su permiso y que las reglas que viven en el controlador —no
 * en el motor— se cumplan.
 *
 * El fixture es propio de este archivo: los helpers de Pest comparten un
 * único espacio de nombres global, y tomar uno de otro archivo ataría este
 * test a que ese archivo se cargue también.
 */
function payrollHttpFixture(): array
{
    ['company' => $company, 'user' => $user] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    $documentType = DocumentType::factory()->create([
        'company_id' => $company->id, 'code' => 'PLA', 'origin_module' => 'planilla',
    ]);

    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'user', 'documentType', 'costCenter');
}

function httpEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "E{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '2'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Ana',
        'last_name1' => "Prueba{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '800000.00',
        'status' => 'active',
    ], $attributes));
}

function httpPeriod(array $f, array $attributes = []): PayrollPeriod
{
    return PayrollPeriod::create(array_merge([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => 4, 'name' => 'Abril 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30', 'payment_date' => '2026-04-30',
        'status' => 'open',
    ], $attributes));
}

// ─────────────────────────────────────────────────────────────────────────

it('carga todas las pantallas del módulo', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);
    httpPeriod($f);

    foreach ([
        route('employees.index'),
        route('employees.show', $employee->id),
        route('payroll-periods.index'),
        route('personnel-actions.index'),
        route('vacations.index'),
        route('employee-deductions.index'),
        route('payroll-settings.index'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

it('exige permiso del módulo de planillas para entrar', function () {
    // Mismo usuario y compañía, pero sin permiso sobre 'payroll': la
    // pantalla tiene que rebotar con 403, no mostrarse a medias.
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $this->actingAs($user)->get(route('employees.index'))->assertForbidden();
});

it('registra la contratación como acción de personal al crear la ficha', function () {
    $f = payrollHttpFixture();

    $this->post(route('employees.store'), [
        'code' => 'E-100',
        'identification_type' => 'cedula',
        'identification_number' => '112345678',
        'first_name' => 'Carlos',
        'last_name1' => 'Rojas',
        'hire_date' => '2026-01-15',
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 900000,
        'payment_method' => 'transferencia',
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $employee = Employee::where('code', 'E-100')->firstOrFail();

    // El historial del trabajador empieza donde empezó de verdad, no en su
    // primer aumento.
    $action = PersonnelAction::where('employee_id', $employee->id)->firstOrFail();

    expect($action->action_type)->toBe('hire')
        ->and($action->status)->toBe('applied')
        ->and($action->effective_date->format('Y-m-d'))->toBe('2026-01-15');
});

it('no deja repetir la identificación dentro de una compañía', function () {
    $f = payrollHttpFixture();
    httpEmployee($f, ['identification_number' => '111222333']);

    $this->post(route('employees.store'), [
        'code' => 'OTRO',
        'identification_type' => 'cedula',
        'identification_number' => '111222333',
        'first_name' => 'Duplicado',
        'last_name1' => 'Duplicado',
        'hire_date' => '2026-01-01',
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 500000,
        'payment_method' => 'efectivo',
        'status' => 'active',
    ])->assertSessionHasErrors('identification_number');
});

it('rechaza un período que se traslapa con otro de la misma frecuencia', function () {
    $f = payrollHttpFixture();
    httpPeriod($f);

    // Dos períodos mensuales que comparten días pagarían dos veces lo mismo.
    $this->post(route('payroll-periods.store'), [
        'year' => 2026, 'frequency' => 'mensual', 'number' => 5, 'name' => 'Traslapado',
        'start_date' => '2026-04-15', 'end_date' => '2026-05-15', 'payment_date' => '2026-05-15',
    ])->assertSessionHasErrors('start_date');
});

it('rechaza una fecha de pago anterior al cierre del período', function () {
    $f = payrollHttpFixture();

    $this->post(route('payroll-periods.store'), [
        'year' => 2026, 'frequency' => 'mensual', 'number' => 6, 'name' => 'Pago adelantado',
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'payment_date' => '2026-06-15',
    ])->assertSessionHasErrors('payment_date');
});

it('exige la cantidad de horas en un concepto que se paga por horas', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);
    $period = httpPeriod($f);

    $extra = PayrollConcept::where('code', 'HE-SIMPLE')->firstOrFail();

    // Sin horas se guardaría en cero y desaparecería del cálculo sin decir
    // nada: alguien tendría que descubrirlo revisando por qué no se pagaron.
    $this->post(route('payroll-periods.inputs.store', $period->id), [
        'employee_id' => $employee->id,
        'payroll_concept_id' => $extra->id,
    ])->assertSessionHasErrors('quantity');

    $this->post(route('payroll-periods.inputs.store', $period->id), [
        'employee_id' => $employee->id,
        'payroll_concept_id' => $extra->id,
        'quantity' => 8,
    ])->assertSessionHasNoErrors();

    expect(PayrollInput::where('payroll_period_id', $period->id)->count())->toBe(1);
});

it('calcula desde los movimientos guardados y no desde el formulario', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);
    $period = httpPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    PayrollInput::create([
        'company_id' => $f['company']->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => $bono->id,
        'amount' => '50000',
    ]);

    // El recálculo es un botón sin cuerpo: los movimientos ya están guardados.
    $this->post(route('payroll-periods.calculate', $period->id))->assertSessionHasNoErrors();

    $entry = $period->fresh()->entries()->firstOrFail();

    expect($period->fresh()->status)->toBe('calculated')
        ->and($entry->total_earnings)->toBe('850000.00');
});

it('no deja tocar los movimientos de un período ya aprobado', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));
    $this->post(route('payroll-periods.approve', $period->id))->assertSessionHasNoErrors();

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    $this->post(route('payroll-periods.inputs.store', $period->id), [
        'employee_id' => $employee->id,
        'payroll_concept_id' => $bono->id,
        'amount' => 10000,
    ])->assertSessionHasErrors('payroll');
});

it('solo aprueba una planilla calculada', function () {
    $f = payrollHttpFixture();
    httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.approve', $period->id))->assertSessionHasErrors('payroll');
});

it('no deja que quien solicita una acción de personal se la apruebe a sí mismo', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    $this->post(route('personnel-actions.store'), [
        'employee_id' => $employee->id,
        'action_type' => 'salary_change',
        'effective_date' => '2026-05-01',
        'new_value' => '1000000',
        'reason' => 'Ajuste anual',
    ])->assertSessionHasNoErrors();

    $action = PersonnelAction::where('employee_id', $employee->id)
        ->where('action_type', 'salary_change')->firstOrFail();

    // Una aprobación que uno se da a sí mismo no es una aprobación.
    $this->post(route('personnel-actions.approve', $action->id))->assertSessionHasErrors('action');

    expect($action->fresh()->status)->toBe('draft');
});

it('congela el valor anterior al crear la acción y solo cambia la ficha al aplicarla', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f, ['base_salary' => '800000.00']);

    $this->post(route('personnel-actions.store'), [
        'employee_id' => $employee->id,
        'action_type' => 'salary_change',
        'effective_date' => '2026-01-01',
        'new_value' => '1200000',
    ]);

    $action = PersonnelAction::where('employee_id', $employee->id)
        ->where('action_type', 'salary_change')->firstOrFail();

    expect($action->previous_value)->toBe('800000.00');

    // Aprobar no toca la ficha.
    $other = User::factory()->create(['default_company_id' => $f['company']->id]);
    $f['company']->users()->attach($other->id);
    grantAllModuleAccess($other, $f['company']);

    $this->actingAs($other)->post(route('personnel-actions.approve', $action->id))->assertSessionHasNoErrors();
    expect($employee->fresh()->base_salary)->toBe('800000.00');

    // Aplicar sí.
    $this->post(route('personnel-actions.apply', $action->id))->assertSessionHasNoErrors();
    expect($employee->fresh()->base_salary)->toBe('1200000.00');
});

it('no aplica una acción cuya vigencia es futura', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    $action = PersonnelAction::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'action_type' => 'salary_change',
        'field' => 'base_salary',
        'effective_date' => now()->addMonth()->format('Y-m-d'),
        'new_value' => '2000000',
        'status' => 'approved',
    ]);

    $this->post(route('personnel-actions.apply', $action->id))->assertSessionHasErrors('action');
});

it('guarda un disfrute de vacaciones en negativo aunque se digite en positivo', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    // Primero se le acredita saldo, si no el disfrute lo dejaría en negativo.
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'adjustment', 'movement_date' => '2026-01-01', 'days' => '12',
        'notes' => 'Saldo inicial',
    ]);

    $this->post(route('vacations.store'), [
        'employee_id' => $employee->id,
        'type' => 'taken',
        'movement_date' => '2026-04-06',
        'days' => 5,
        'from_date' => '2026-04-06',
        'to_date' => '2026-04-10',
    ])->assertSessionHasNoErrors();

    // Digitado en positivo, guardado en negativo: si se guardara tal cual, el
    // saldo CRECERÍA cada vez que alguien saliera de vacaciones.
    $taken = VacationMovement::where('employee_id', $employee->id)->where('type', 'taken')->firstOrFail();

    expect((string) $taken->days)->toBe('-5.0000')
        ->and($employee->fresh()->vacationBalance())->toBe('7.0000');
});

it('avisa antes de dejar el saldo de vacaciones en negativo', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    $this->post(route('vacations.store'), [
        'employee_id' => $employee->id,
        'type' => 'taken',
        'movement_date' => '2026-04-06',
        'days' => 10,
    ])->assertSessionHasErrors('days');

    // Como ajuste explícito sí pasa: adelantar vacaciones puede ser una
    // decisión, pero tiene que ser una decisión.
    $this->post(route('vacations.store'), [
        'employee_id' => $employee->id,
        'type' => 'adjustment',
        'movement_date' => '2026-04-06',
        'days' => -10,
        'notes' => 'Vacaciones adelantadas por acuerdo',
    ])->assertSessionHasNoErrors();
});

it('arranca el saldo de un préstamo con el monto otorgado si no se digita', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    $this->post(route('employee-deductions.store'), [
        'employee_id' => $employee->id,
        'type' => 'loan',
        'description' => 'Préstamo de consumo',
        'start_date' => '2026-01-01',
        'original_amount' => 600000,
        'calculation' => 'amount',
        'installment_amount' => 50000,
        'priority' => 60,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $loan = EmployeeDeduction::where('employee_id', $employee->id)->firstOrFail();

    expect($loan->balance)->toBe('600000.00');
});

it('deja sin saldo una obligación que no se extingue', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);

    $this->post(route('employee-deductions.store'), [
        'employee_id' => $employee->id,
        'type' => 'solidarista_savings',
        'description' => 'Ahorro solidarista',
        'start_date' => '2026-01-01',
        'calculation' => 'percentage',
        'installment_percentage' => 3,
        'priority' => 50,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $saving = EmployeeDeduction::where('employee_id', $employee->id)->firstOrFail();

    // Null, no cero: cero sería una obligación ya pagada.
    expect($saving->balance)->toBeNull();
});

it('no deja borrar un empleado que ya tiene planillas', function () {
    $f = payrollHttpFixture();
    $employee = httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));

    $this->delete(route('employees.destroy', $employee->id))->assertSessionHasErrors('employee');

    expect(Employee::find($employee->id))->not->toBeNull();
});

it('rechaza el archivo de pago de una planilla que todavía no está aprobada', function () {
    $f = payrollHttpFixture();
    httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));

    // Pagar un cálculo que aún puede cambiar es el error caro.
    $this->get(route('payroll-periods.bank-file', $period->id))->assertSessionHasErrors('payroll');
});

it('rechaza el archivo de pago cuando alguien cobra por transferencia y no tiene cuenta', function () {
    $f = payrollHttpFixture();
    httpEmployee($f, ['payment_method' => 'transferencia', 'bank_account' => null]);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));
    $this->post(route('payroll-periods.approve', $period->id));

    // Omitirlo en silencio produce el error que nadie detecta hasta que esa
    // persona llama a decir que no le llegó el salario.
    $this->get(route('payroll-periods.bank-file', $period->id))->assertSessionHasErrors('payroll');
});

it('genera el archivo de pago con una línea por trabajador con cuenta', function () {
    $f = payrollHttpFixture();
    httpEmployee($f, ['bank_account' => 'CR05015202001026284066']);
    httpEmployee($f, ['payment_method' => 'efectivo']);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));
    $this->post(route('payroll-periods.approve', $period->id));

    $response = $this->get(route('payroll-periods.bank-file', $period->id));

    $response->assertOk();

    $lines = array_values(array_filter(explode("\r\n", $response->getContent())));

    // Encabezado más UNA línea: quien cobra en efectivo no va al banco.
    expect($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('CR05015202001026284066');
});

it('exporta la planilla en XLSX', function () {
    $f = payrollHttpFixture();
    httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));

    $this->get(route('payroll-periods.export', $period->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('carga la plantilla de Costa Rica de forma idempotente', function () {
    $f = payrollHttpFixture();

    $before = PayrollContribution::count();

    $this->post(route('payroll-settings.load-defaults'), ['valid_from' => '2026-01-01'])
        ->assertSessionHasNoErrors();

    expect(PayrollContribution::count())->toBe($before);
});

it('muestra el comprobante de pago con su base y su tasa', function () {
    $f = payrollHttpFixture();
    httpEmployee($f);
    $period = httpPeriod($f);

    $this->post(route('payroll-periods.calculate', $period->id));

    $entry = $period->fresh()->entries()->firstOrFail();

    $this->get(route('payslips.show', $entry->id))->assertOk();
    $this->get(route('payslips.print', $entry->id))->assertOk();
});
