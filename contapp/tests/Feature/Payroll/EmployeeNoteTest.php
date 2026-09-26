<?php

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;
use App\Models\User;

/**
 * La bitácora del funcionario: hechos con fecha que no se editan.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function noteFixture(): array
{
    ['company' => $company, 'user' => $user] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    $employee = Employee::create([
        'company_id' => $company->id,
        'code' => 'N1',
        'identification_type' => 'cedula',
        'identification_number' => '700000001',
        'first_name' => 'Nota',
        'last_name1' => 'Prueba',
        'hire_date' => '2024-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '700000.00',
        'weekly_hours' => '48',
        'status' => 'active',
    ]);

    return compact('company', 'user', 'employee');
}

// ─────────────────────────────────────────────────────────────────────────

it('registra una anotación con la fecha del hecho', function () {
    $f = noteFixture();

    $this->post(route('employee-notes.store', $f['employee']->id), [
        'happened_on' => '2026-03-10',
        'category' => 'warning',
        'title' => 'Llegadas tardías',
        'body' => 'Tres llegadas tardías en la primera semana de marzo.',
    ])->assertSessionHasNoErrors();

    $note = EmployeeNote::where('employee_id', $f['employee']->id)->firstOrFail();

    // La fecha del hecho es la que ordena la bitácora, no la de captura: se
    // registra hoy algo que pasó la semana pasada.
    expect($note->happened_on->format('Y-m-d'))->toBe('2026-03-10')
        ->and($note->category)->toBe('warning')
        ->and($note->created_by)->toBe($f['user']->id);
});

it('no acepta una anotación anterior al ingreso del trabajador', function () {
    $f = noteFixture();

    $this->post(route('employee-notes.store', $f['employee']->id), [
        'happened_on' => '2023-06-01',
        'category' => 'observation',
        'title' => 'Antes de entrar',
        'body' => 'Un hecho anterior a la relación laboral.',
    ])->assertSessionHasErrors('happened_on');

    expect(EmployeeNote::count())->toBe(0);
});

it('no deja borrar la anotación de otra persona', function () {
    $f = noteFixture();

    $note = EmployeeNote::create([
        'company_id' => $f['company']->id,
        'employee_id' => $f['employee']->id,
        'happened_on' => '2026-03-10',
        'category' => 'incident',
        'title' => 'Incidente',
        'body' => 'Lo anotó otra persona.',
        'created_by' => User::factory()->create()->id,
    ]);

    // Una bitácora en la que cualquiera borra lo que otro escribió no
    // sustenta nada.
    $this->delete(route('employee-notes.destroy', [$f['employee']->id, $note->id]))
        ->assertSessionHasErrors('note');

    expect(EmployeeNote::find($note->id))->not->toBeNull();
});

it('no deja borrar una anotación de días anteriores, ni siendo el autor', function () {
    $f = noteFixture();

    $note = EmployeeNote::create([
        'company_id' => $f['company']->id,
        'employee_id' => $f['employee']->id,
        'happened_on' => '2026-03-10',
        'category' => 'observation',
        'title' => 'Vieja',
        'body' => 'Escrita hace una semana.',
        'created_by' => $f['user']->id,
    ]);

    $note->forceFill(['created_at' => now()->subWeek()])->saveQuietly();

    // El valor de la anotación está en que se hizo cuando pasó el hecho.
    // Para corregir, se anota encima.
    $this->delete(route('employee-notes.destroy', [$f['employee']->id, $note->id]))
        ->assertSessionHasErrors('note');

    expect(EmployeeNote::find($note->id))->not->toBeNull();
});

it('deja borrar el error de dedo del mismo día, al propio autor', function () {
    $f = noteFixture();

    $this->post(route('employee-notes.store', $f['employee']->id), [
        'happened_on' => '2026-03-10',
        'category' => 'observation',
        'title' => 'Me equivoqué de empleado',
        'body' => 'Texto que iba para otra ficha.',
    ]);

    $note = EmployeeNote::firstOrFail();

    $this->delete(route('employee-notes.destroy', [$f['employee']->id, $note->id]))
        ->assertSessionHasNoErrors();

    expect(EmployeeNote::count())->toBe(0);
});

it('la ficha muestra la bitácora, con lo más reciente primero', function () {
    $f = noteFixture();

    foreach (['2026-01-15' => 'Enero', '2026-03-10' => 'Marzo', '2026-02-20' => 'Febrero'] as $date => $title) {
        EmployeeNote::create([
            'company_id' => $f['company']->id,
            'employee_id' => $f['employee']->id,
            'happened_on' => $date,
            'category' => 'observation',
            'title' => $title,
            'body' => 'Detalle.',
            'created_by' => $f['user']->id,
        ]);
    }

    // Se ordena por la fecha del HECHO: digitadas en desorden, se leen en
    // orden.
    $this->get(route('employees.show', $f['employee']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notes', 3)
            ->where('notes.0.title', 'Marzo')
            ->where('notes.2.title', 'Enero'));
});

// ── Inactivación automática ──────────────────────────────────────────────

it('inactiva al trabajador solo con llenarle la fecha de salida', function () {
    $f = noteFixture();

    $this->put(route('employees.update', $f['employee']->id), [
        'code' => 'N1',
        'identification_type' => 'cedula',
        'identification_number' => '700000001',
        'first_name' => 'Nota',
        'last_name1' => 'Prueba',
        'hire_date' => '2024-01-01',
        'termination_date' => '2026-06-30',
        'termination_reason' => 'renuncia',
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 700000,
        'payment_method' => 'efectivo',
        // Se manda activo a propósito: el sistema tiene que corregirlo.
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    // Olvidar cambiar el estado lo dejaba apareciendo como activo en la
    // verificación previa y en la planilla siguiente.
    expect($f['employee']->fresh()->status)->toBe('terminated');
});

it('borrar la fecha de salida NO reactiva al trabajador', function () {
    $f = noteFixture();
    $f['employee']->update(['status' => 'terminated', 'termination_date' => '2026-06-30']);

    $this->put(route('employees.update', $f['employee']->id), [
        'code' => 'N1',
        'identification_type' => 'cedula',
        'identification_number' => '700000001',
        'first_name' => 'Nota',
        'last_name1' => 'Prueba',
        'hire_date' => '2024-01-01',
        'termination_date' => null,
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 700000,
        'payment_method' => 'efectivo',
        'status' => 'terminated',
    ])->assertSessionHasNoErrors();

    // Una reincorporación es una decisión con su propia acción de personal,
    // no la consecuencia de vaciar un campo.
    expect($f['employee']->fresh()->status)->toBe('terminated');
});
