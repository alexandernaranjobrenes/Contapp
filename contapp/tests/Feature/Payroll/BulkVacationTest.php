<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\BulkVacationService;

/**
 * El mismo movimiento de vacaciones para muchos trabajadores: el cierre de
 * fin de año, los saldos iniciales.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function bulkFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    return compact('company');
}

function bulkEmployee(array $f, string $balance = '0'): Employee
{
    static $n = 0;
    $n++;

    $employee = Employee::create([
        'company_id' => $f['company']->id,
        'code' => "B{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '1'.str_pad((string) (100000000 + $n), 9, '0', STR_PAD_LEFT),
        'first_name' => 'Masivo',
        'last_name1' => "Prueba{$n}",
        'hire_date' => '2020-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '800000.00',
        'weekly_hours' => '48',
        'status' => 'active',
    ]);

    if (bccomp($balance, '0', 4) !== 0) {
        VacationMovement::create([
            'company_id' => $f['company']->id,
            'employee_id' => $employee->id,
            'type' => 'adjustment',
            'movement_date' => '2026-01-01',
            'days' => $balance,
            'notes' => 'Saldo inicial',
        ]);
    }

    return $employee;
}

// ─────────────────────────────────────────────────────────────────────────

it('aplica el mismo movimiento a todos los seleccionados', function () {
    $f = bulkFixture();
    $uno = bulkEmployee($f, '15');
    $dos = bulkEmployee($f, '20');
    $fuera = bulkEmployee($f, '10');

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$uno->id, $dos->id],
        'type' => 'taken',
        'movement_date' => '2026-12-24',
        'days' => 6,
        'from_date' => '2026-12-24',
        'to_date' => '2026-12-29',
        'notes' => 'Cierre de fin de año',
    ])->assertSessionHasNoErrors();

    // Se rebaja a los dos elegidos y no al tercero.
    expect($uno->fresh()->vacationBalance())->toBe('9.0000')
        ->and($dos->fresh()->vacationBalance())->toBe('14.0000')
        ->and($fuera->fresh()->vacationBalance())->toBe('10.0000');
});

it('guarda el disfrute en negativo aunque se digite en positivo', function () {
    $f = bulkFixture();
    $employee = bulkEmployee($f, '15');

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$employee->id],
        'type' => 'taken',
        'movement_date' => '2026-12-24',
        'days' => 6,
    ])->assertSessionHasNoErrors();

    $movement = VacationMovement::where('employee_id', $employee->id)->where('type', 'taken')->firstOrFail();

    expect((string) $movement->days)->toBe('-6.0000');
});

it('no aplica NADA si a uno solo no le alcanza el saldo', function () {
    $f = bulkFixture();
    $conSaldo = bulkEmployee($f, '15');
    $sinSaldo = bulkEmployee($f, '2');

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$conSaldo->id, $sinSaldo->id],
        'type' => 'taken',
        'movement_date' => '2026-12-24',
        'days' => 6,
    ])->assertSessionHasErrors('movement');

    // Aplicar los que caben dejaría un cierre a medias: unos con los días
    // rebajados y otros no, sin nada que indique cuáles quedaron pendientes.
    expect($conSaldo->fresh()->vacationBalance())->toBe('15.0000')
        ->and($sinSaldo->fresh()->vacationBalance())->toBe('2.0000')
        ->and(VacationMovement::where('type', 'taken')->count())->toBe(0);
});

it('el aviso dice a quién le falta saldo y cuánto tiene', function () {
    $f = bulkFixture();
    $sinSaldo = bulkEmployee($f, '2');

    // Se verifica el mensaje donde se produce. Sin el código y el saldo, el
    // usuario tendría que ir a buscar quién de los cincuenta es.
    $message = null;

    try {
        app(BulkVacationService::class)->apply(
            $f['company'], [$sinSaldo->id], 'taken', '2026-12-24', '6', null, null, null, null
        );
    } catch (InvalidPayrollException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($message)->toContain($sinSaldo->code)
        ->and($message)->toContain('2.0000')
        ->and($message)->toContain('No se aplicó nada');
});

it('un ajuste sí puede dejar el saldo negativo, porque es una decisión', function () {
    $f = bulkFixture();
    $employee = bulkEmployee($f);

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$employee->id],
        'type' => 'adjustment',
        'movement_date' => '2026-06-01',
        'days' => -5,
        'notes' => 'Vacaciones adelantadas por acuerdo',
    ])->assertSessionHasNoErrors();

    expect($employee->fresh()->vacationBalance())->toBe('-5.0000');
});

it('acredita días a varios de una vez', function () {
    $f = bulkFixture();
    $uno = bulkEmployee($f);
    $dos = bulkEmployee($f);

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$uno->id, $dos->id],
        'type' => 'adjustment',
        'movement_date' => '2026-01-01',
        'days' => 12,
        'notes' => 'Saldos iniciales al arrancar con el sistema',
    ])->assertSessionHasNoErrors();

    expect($uno->fresh()->vacationBalance())->toBe('12.0000')
        ->and($dos->fresh()->vacationBalance())->toBe('12.0000');
});

it('exige al menos un trabajador', function () {
    bulkFixture();

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [],
        'type' => 'taken',
        'movement_date' => '2026-12-24',
        'days' => 6,
    ])->assertSessionHasErrors('employee_ids');
});

it('no acepta un trabajador de otra compañía', function () {
    $f = bulkFixture();
    $propio = bulkEmployee($f, '15');

    $otra = Company::factory()->create();
    $ajeno = Employee::create([
        'company_id' => $otra->id, 'code' => 'AJENO',
        'identification_type' => 'cedula', 'identification_number' => '555444333',
        'first_name' => 'Ajeno', 'last_name1' => 'Ajeno',
        'hire_date' => '2020-01-01', 'salary_type' => 'mensual',
        'base_salary' => '500000.00', 'weekly_hours' => '48', 'status' => 'active',
    ]);

    $this->post(route('vacations.bulk'), [
        'employee_ids' => [$propio->id, $ajeno->id],
        'type' => 'adjustment',
        'movement_date' => '2026-01-01',
        'days' => 5,
    ])->assertSessionHasErrors();

    expect(VacationMovement::where('employee_id', $ajeno->id)->count())->toBe(0);
});
