<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\DataTransferObjects\PayrollInputLine;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeRecurringInput;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;

/**
 * Rubros fijos: los que se aplican en cada período sin digitarse.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function recurringFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    // El catálogo entra con todo en no-recurrente; se habilitan los dos que
    // los tests usan como fijos.
    PayrollConcept::whereIn('code', ['BONO', 'OTRA-DED'])->update(['is_recurring' => true]);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'costCenter');
}

function recurringEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "F{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '4'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Fija',
        'last_name1' => "Persona{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '1000000.00',
        'weekly_hours' => '48',
        'is_ccss_exempt' => true,
        'status' => 'active',
    ], $attributes));
}

function recurringPeriod(array $f, int $number = 3, string $start = '2026-03-01', string $end = '2026-03-31'): PayrollPeriod
{
    return PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => $number,
        'name' => "Período {$number} 2026",
        'start_date' => $start, 'end_date' => $end, 'payment_date' => $end,
        'status' => 'open',
    ]);
}

function recurringConcept(string $code): PayrollConcept
{
    return PayrollConcept::where('code', $code)->firstOrFail();
}

function runPayroll(array $f, PayrollPeriod $period, array $inputs = []): PayrollEntry
{
    app(CalculatePayrollService::class)->calculate($f['company'], $period, $inputs);

    return PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();
}

// ─────────────────────────────────────────────────────────────────────────

it('aplica el rubro fijo sin que nadie lo digite', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $entry = runPayroll($f, recurringPeriod($f));

    expect($entry->total_earnings)->toBe('1025000.00')
        ->and($entry->lines->firstWhere('code', 'BONO'))->not->toBeNull();
});

it('lo vuelve a aplicar el período siguiente sin reactivarlo', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    expect(runPayroll($f, recurringPeriod($f, 3))->total_earnings)->toBe('1025000.00')
        ->and(runPayroll($f, recurringPeriod($f, 4, '2026-04-01', '2026-04-30'))->total_earnings)->toBe('1025000.00');
});

it('lo DIGITADO reemplaza al rubro fijo en vez de sumarse', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);
    $bono = recurringConcept('BONO');

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => $bono->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    // Este mes la bonificación fue de 40.000: quiso decir que fue de 40.000,
    // no que se le paguen 65.000. Sumarlas convertiría una corrección en un
    // pago doble que nadie decidió.
    $entry = runPayroll($f, recurringPeriod($f), [
        new PayrollInputLine($employee->id, $bono->id, amount: '40000'),
    ]);

    expect($entry->total_earnings)->toBe('1040000.00')
        ->and($entry->lines->where('code', 'BONO'))->toHaveCount(1);
});

it('digitar otro rubro no le quita el fijo', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    // El reemplazo es por empleado Y concepto, no por empleado.
    $entry = runPayroll($f, recurringPeriod($f), [
        new PayrollInputLine($employee->id, recurringConcept('HE-SIMPLE')->id, quantity: 4),
    ]);

    expect($entry->lines->firstWhere('code', 'BONO'))->not->toBeNull()
        ->and($entry->lines->firstWhere('code', 'HE-SIMPLE'))->not->toBeNull();
});

it('respeta la vigencia: no se aplica antes de empezar ni después de terminar', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
        'status' => 'active',
    ]);

    // Marzo: todavía no rige.
    expect(runPayroll($f, recurringPeriod($f, 3))->total_earnings)->toBe('1000000.00');

    // Abril: rige.
    expect(runPayroll($f, recurringPeriod($f, 4, '2026-04-01', '2026-04-30'))->total_earnings)->toBe('1025000.00');

    // Mayo: ya venció, y entró solo sin que nadie lo apagara.
    expect(runPayroll($f, recurringPeriod($f, 5, '2026-05-01', '2026-05-31'))->total_earnings)->toBe('1000000.00');
});

it('un rubro fijo suspendido no se aplica', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'suspended',
    ]);

    expect(runPayroll($f, recurringPeriod($f))->total_earnings)->toBe('1000000.00');
});

it('un rubro fijo de deducción rebaja el neto', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('OTRA-DED')->id,
        'amount' => '15000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $entry = runPayroll($f, recurringPeriod($f));

    expect($entry->total_other_deductions)->toBe('15000.00')
        ->and($entry->total_earnings)->toBe('1000000.00');
});

it('el rubro fijo de un empleado no se le aplica a otro', function () {
    $f = recurringFixture();
    $conBono = recurringEmployee($f);
    recurringEmployee($f);

    EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $conBono->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $period = recurringPeriod($f);
    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);

    $entries = PayrollEntry::where('payroll_period_id', $period->id)->get()->keyBy('employee_id');

    expect($entries)->toHaveCount(2)
        ->and($entries[$conBono->id]->total_earnings)->toBe('1025000.00')
        ->and($entries->except($conBono->id)->first()->total_earnings)->toBe('1000000.00');
});

// ── La puerta de entrada ─────────────────────────────────────────────────

it('solo deja asignar conceptos marcados como recurrentes', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    // VIATICO existe y está activo, pero no está marcado como asignable en
    // fijo: sin esto, cualquiera podría dejar fijo un rubro ocasional.
    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('VIATICO')->id,
        'amount' => 10000,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertSessionHasErrors('payroll_concept_id');
});

it('rechaza dos vigencias del mismo rubro que se traslapan', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);
    $bono = recurringConcept('BONO')->id;

    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id, 'payroll_concept_id' => $bono,
        'amount' => 25000, 'start_date' => '2026-01-01', 'status' => 'active',
    ])->assertSessionHasNoErrors();

    // El primero es indefinido, así que cualquier fecha posterior se traslapa.
    // Dos vigentes a la vez serían un pago doble que nadie pidió.
    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id, 'payroll_concept_id' => $bono,
        'amount' => 30000, 'start_date' => '2026-06-01', 'status' => 'active',
    ])->assertSessionHasErrors('start_date');

    expect(EmployeeRecurringInput::count())->toBe(1);
});

it('acepta la vigencia nueva si la anterior ya está cerrada', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);
    $bono = recurringConcept('BONO')->id;

    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id, 'payroll_concept_id' => $bono,
        'amount' => 25000, 'start_date' => '2026-01-01', 'end_date' => '2026-05-31', 'status' => 'active',
    ])->assertSessionHasNoErrors();

    // Así se carga un aumento: se cierra el anterior y se abre el nuevo, y
    // entra solo el día que corresponde.
    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id, 'payroll_concept_id' => $bono,
        'amount' => 30000, 'start_date' => '2026-06-01', 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(EmployeeRecurringInput::count())->toBe(2);
});

it('exige el monto en un rubro por monto y las horas en uno por horas', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    // Sin monto se guardaría en cero y desaparecería del cálculo sin avisar.
    $this->post(route('recurring-inputs.store'), [
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'start_date' => '2026-01-01', 'status' => 'active',
    ])->assertSessionHasErrors('amount');
});

it('la pantalla carga y distingue el catálogo vacío del catálogo sin marcar', function () {
    $f = recurringFixture();

    // El total se deriva de la plantilla y no se cuenta a mano: un número
    // fijo acá se rompe cada vez que se agregue un concepto al catálogo, y el
    // fallo no diría nada sobre esta pantalla.
    $expected = count(CostaRicaPayrollDefaults::CONCEPTS);

    $this->get(route('recurring-inputs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignableCount', 2)
            ->where('conceptCount', $expected));
});

it('borrar un rubro fijo no cambia las planillas ya calculadas', function () {
    $f = recurringFixture();
    $employee = recurringEmployee($f);

    $fixed = EmployeeRecurringInput::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => recurringConcept('BONO')->id,
        'amount' => '25000',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $entry = runPayroll($f, recurringPeriod($f));
    expect($entry->total_earnings)->toBe('1025000.00');

    $this->delete(route('recurring-inputs.destroy', $fixed->id))->assertSessionHasNoErrors();

    // La línea quedó congelada en la boleta: borrar la asignación cambia de
    // acá en adelante, no el pasado.
    expect($entry->fresh()->total_earnings)->toBe('1025000.00')
        ->and($entry->fresh()->lines->firstWhere('code', 'BONO'))->not->toBeNull();
});
