<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;

/**
 * Borrar un período calculado tiene que deshacer TODO lo que el cálculo
 * movió fuera de la planilla.
 *
 * El cálculo no solo escribe boletas: rebaja el saldo de los préstamos y
 * acredita días de vacaciones. Si el período se borra y esos dos efectos
 * quedan, el trabajador termina debiendo menos de lo que debe y con días de
 * vacaciones que nadie le acreditó — y no hay ningún documento que lo
 * explique, porque el período que los produjo ya no existe.
 */
function deletionFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'costCenter');
}

function deletionEmployee(array $f): Employee
{
    static $n = 0;
    $n++;

    return Employee::create([
        'company_id' => $f['company']->id,
        'code' => "D{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '5'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Borra',
        'last_name1' => "Prueba{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '900000.00',
        'weekly_hours' => '48',
        'status' => 'active',
    ]);
}

function deletionPeriod(array $f): PayrollPeriod
{
    return PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => 9, 'name' => 'Setiembre 2026',
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'payment_date' => '2026-09-30',
        'status' => 'open',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────

it('devuelve el saldo del préstamo al borrar un período calculado', function () {
    $f = deletionFixture();
    $employee = deletionEmployee($f);
    $period = deletionPeriod($f);

    $loan = EmployeeDeduction::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'type' => 'loan',
        'description' => 'Préstamo',
        'start_date' => '2026-01-01',
        'original_amount' => '300000',
        'balance' => '300000',
        'calculation' => 'amount',
        'installment_amount' => '50000',
        'priority' => 60,
        'status' => 'active',
    ]);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);

    expect((string) $loan->fresh()->balance)->toBe('250000.00');

    // Borrar el período es descartar el cálculo: el rebajo nunca ocurrió.
    $this->delete(route('payroll-periods.destroy', $period->id))->assertSessionHasNoErrors();

    expect((string) $loan->fresh()->balance)->toBe('300000.00')
        ->and($loan->fresh()->applications()->count())->toBe(0);
});

it('quita las vacaciones acreditadas al borrar un período calculado', function () {
    $f = deletionFixture();
    $employee = deletionEmployee($f);
    $period = deletionPeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);

    expect($employee->fresh()->vacationBalance())->toBe('1.0000');

    $this->delete(route('payroll-periods.destroy', $period->id))->assertSessionHasNoErrors();

    // Sin esto la acreditación sobrevivía al período con su vínculo en null:
    // días acreditados que ya nadie puede rastrear hasta su origen.
    expect($employee->fresh()->vacationBalance())->toBe('0.0000')
        ->and(VacationMovement::where('employee_id', $employee->id)->count())->toBe(0);
});

it('no borra los movimientos de vacaciones digitados a mano', function () {
    $f = deletionFixture();
    $employee = deletionEmployee($f);
    $period = deletionPeriod($f);

    $ajuste = VacationMovement::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'type' => 'adjustment',
        'movement_date' => '2026-01-01',
        'days' => '5',
        'notes' => 'Saldo inicial',
    ]);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    expect($employee->fresh()->vacationBalance())->toBe('6.0000');

    $this->delete(route('payroll-periods.destroy', $period->id))->assertSessionHasNoErrors();

    // Se deshace lo que hizo el cálculo, no lo que digitó una persona.
    expect(VacationMovement::find($ajuste->id))->not->toBeNull()
        ->and($employee->fresh()->vacationBalance())->toBe('5.0000');
});

it('sigue sin dejar borrar un período contabilizado', function () {
    $f = deletionFixture();
    deletionEmployee($f);
    $period = deletionPeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    $period->update(['status' => 'posted']);

    $this->delete(route('payroll-periods.destroy', $period->id))->assertSessionHasErrors('payroll');

    expect(PayrollPeriod::find($period->id))->not->toBeNull()
        ->and(PayrollEntry::where('payroll_period_id', $period->id)->count())->toBeGreaterThan(0);
});
