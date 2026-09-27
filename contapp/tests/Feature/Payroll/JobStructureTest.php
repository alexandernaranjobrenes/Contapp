<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\JobPosition;

/**
 * Departamentos y puestos como catálogo, y el proceso masivo de vacaciones.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function structureFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'costCenter');
}

function structureEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "S{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '9'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Puesto',
        'last_name1' => "Prueba{$n}",
        'hire_date' => '2020-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '800000.00',
        'weekly_hours' => '48',
        'status' => 'active',
    ], $attributes));
}

// ── Departamentos ────────────────────────────────────────────────────────

it('crea un departamento con su centro de costo', function () {
    $f = structureFixture();

    $this->post(route('departments.store'), [
        'code' => 'CONTA',
        'name' => 'Contabilidad',
        'cost_center_id' => $f['costCenter']->id,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(Department::where('code', 'CONTA')->firstOrFail()->cost_center_id)
        ->toBe($f['costCenter']->id);
});

it('no deja repetir el código dentro de la compañía', function () {
    $f = structureFixture();

    Department::create(['company_id' => $f['company']->id, 'code' => 'CONTA', 'name' => 'Contabilidad', 'status' => 'active']);

    $this->post(route('departments.store'), [
        'code' => 'CONTA', 'name' => 'Otro', 'status' => 'active',
    ])->assertSessionHasErrors('code');
});

it('no borra un departamento con gente adentro', function () {
    $f = structureFixture();

    $department = Department::create([
        'company_id' => $f['company']->id, 'code' => 'PROD', 'name' => 'Producción', 'status' => 'active',
    ]);

    structureEmployee($f, ['department_id' => $department->id]);

    // Las fichas quedarían apuntando al vacío y los reportes por
    // departamento perderían ese grupo entero.
    $this->delete(route('departments.destroy', $department->id))->assertSessionHasErrors('structure');

    expect(Department::find($department->id))->not->toBeNull();
});

// ── Puestos ──────────────────────────────────────────────────────────────

it('guarda el código de ocupación de la CCSS en el puesto', function () {
    $f = structureFixture();

    $department = Department::create([
        'company_id' => $f['company']->id, 'code' => 'PROD', 'name' => 'Producción', 'status' => 'active',
    ]);

    $this->post(route('job-positions.store'), [
        'code' => 'OPER',
        'name' => 'Operario de planta',
        'department_id' => $department->id,
        'ccss_occupation_code' => '8121',
        'ccss_occupation_name' => 'Operadores de instalaciones',
        'min_salary' => 400000,
        'max_salary' => 700000,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    // Va en el puesto y no en el trabajador: todos los que ocupan el mismo
    // puesto reportan la misma ocupación.
    $position = JobPosition::where('code', 'OPER')->firstOrFail();

    expect($position->ccss_occupation_code)->toBe('8121')
        ->and($position->department_id)->toBe($department->id);
});

it('rechaza un rango salarial invertido', function () {
    $f = structureFixture();

    // Un máximo por debajo del mínimo deja un rango que ningún salario puede
    // cumplir, y la advertencia saltaría siempre.
    $this->post(route('job-positions.store'), [
        'code' => 'RARO', 'name' => 'Rango imposible',
        'min_salary' => 900000, 'max_salary' => 500000, 'status' => 'active',
    ])->assertSessionHasErrors('max_salary');
});

it('detecta un salario fuera del rango del puesto', function () {
    $f = structureFixture();

    $position = JobPosition::create([
        'company_id' => $f['company']->id, 'code' => 'OPER', 'name' => 'Operario',
        'min_salary' => '400000', 'max_salary' => '700000', 'status' => 'active',
    ]);

    expect($position->salaryOutOfRange('550000'))->toBeFalse()
        ->and($position->salaryOutOfRange('350000'))->toBeTrue()
        // El caso que importa: un cero de más.
        ->and($position->salaryOutOfRange('7000000'))->toBeTrue();
});

it('un puesto sin rango no marca nada', function () {
    $f = structureFixture();

    $position = JobPosition::create([
        'company_id' => $f['company']->id, 'code' => 'LIBRE', 'name' => 'Sin rango', 'status' => 'active',
    ]);

    expect($position->salaryOutOfRange('99999999'))->toBeFalse();
});

it('no acepta un departamento de otra compañía', function () {
    structureFixture();

    $otra = Company::factory()->create();
    $ajeno = Department::create([
        'company_id' => $otra->id, 'code' => 'AJENO', 'name' => 'Ajeno', 'status' => 'active',
    ]);

    $this->post(route('job-positions.store'), [
        'code' => 'X', 'name' => 'Puesto', 'department_id' => $ajeno->id, 'status' => 'active',
    ])->assertSessionHasErrors('department_id');
});

// ── La ficha del empleado ────────────────────────────────────────────────

it('la ficha muestra el catálogo cuando lo tiene y el texto cuando no', function () {
    $f = structureFixture();

    $department = Department::create([
        'company_id' => $f['company']->id, 'code' => 'PROD', 'name' => 'Producción', 'status' => 'active',
    ]);

    $conCatalogo = structureEmployee($f, ['department_id' => $department->id, 'department' => 'texto viejo']);
    $soloTexto = structureEmployee($f, ['department' => 'Escrito a mano']);

    // Las dos formas conviven mientras las fichas viejas se mapean: adoptar
    // el catálogo no puede dejar en blanco lo que ya estaba escrito.
    expect($conCatalogo->departmentLabel())->toBe('Producción')
        ->and($soloTexto->departmentLabel())->toBe('Escrito a mano');
});

it('la pantalla de estructura carga', function () {
    $f = structureFixture();

    Department::create(['company_id' => $f['company']->id, 'code' => 'D1', 'name' => 'Uno', 'status' => 'active']);
    JobPosition::create(['company_id' => $f['company']->id, 'code' => 'P1', 'name' => 'Puesto', 'status' => 'active']);

    $this->get(route('job-structure.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('departments', 1)->has('positions', 1));
});
