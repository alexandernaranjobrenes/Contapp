<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Las herramientas de Conti: qué ve y qué no
|--------------------------------------------------------------------------
|
| Conti ve lo que la persona puede ver en el menú, nada de otra compañía y
| nunca datos sensibles. Un error no rompe la conversación: vuelve como
| dato, para que el modelo se lo explique a la persona.
|
*/

it('consulta un conjunto que su pantalla le da, y de uno que no dice qué permiso falta', function () {
    ['user' => $user, 'company' => $company] = contiUser(['business_partners.partners' => 'read']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-100', 'name' => 'Ferretería Central']);
    $tools = contiTools($user, $company);

    $partners = $tools->run('consultar', ['consulta' => 'socios']);
    expect($partners['total'])->toBe(1)
        ->and($partners['registros'][0]['codigo'])->toBe('C-100');

    $employees = $tools->run('consultar', ['consulta' => 'empleados']);
    expect($employees['codigo'])->toBe(403)
        ->and($employees['error'])->toContain('hace falta Lectura en «Planillas → Empleados»');
});

it('nunca ve datos de otra compañía, ni pidiéndolos por id o código', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    ['company' => $other] = contiUser([], null, true);
    $foreign = BusinessPartner::factory()->create(['company_id' => $other->id, 'code' => 'C-999']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-001']);
    $tools = contiTools($user, $company);

    expect(collect($tools->run('consultar', ['consulta' => 'socios'])['registros'])->pluck('codigo')->all())->toBe(['C-001'])
        ->and($tools->run('consultar', ['consulta' => 'socios', 'parametros' => ['id' => $foreign->id]])['codigo'])->toBe(404)
        ->and($tools->run('consultar', ['consulta' => 'socios', 'parametros' => ['id' => 'C-999']])['codigo'])->toBe(404);
});

it('no le llegan correos, teléfonos ni datos personales', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    BusinessPartner::factory()->create([
        'company_id' => $company->id, 'code' => 'C-001', 'email' => 'cliente@ejemplo.com', 'phone' => '8888-8888',
    ]);
    $employee = Employee::create([
        'company_id' => $company->id, 'code' => 'E-001', 'identification_type' => 'cedula', 'identification_number' => '1-1111-1111',
        'ccss_number' => '111111111', 'first_name' => 'Ana', 'last_name1' => 'Mora', 'birth_date' => '1990-01-01',
        'email' => 'ana@ejemplo.com', 'phone' => '7777-7777', 'hire_date' => '2024-01-01', 'salary_type' => 'mensual',
        'base_salary' => '800000', 'bank_account' => 'CR05015202001026284066', 'status' => 'active',
    ]);
    EmployeeNote::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'happened_on' => '2025-01-01', 'category' => 'warning', 'title' => 'Privada', 'body' => 'x', 'is_confidential' => true]);
    EmployeeNote::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'happened_on' => '2025-01-02', 'category' => 'recognition', 'title' => 'Pública', 'body' => 'y', 'is_confidential' => false]);
    $tools = contiTools($user, $company);

    $partner = json_encode($tools->run('consultar', ['consulta' => 'socios', 'parametros' => ['id' => 'C-001']]));
    $person = $tools->run('consultar', ['consulta' => 'empleados', 'parametros' => ['id' => 'E-001']]);
    $notes = $tools->run('consultar', ['consulta' => 'anotaciones-empleado']);

    expect($partner)->not->toContain('cliente@ejemplo.com')->not->toContain('8888-8888')
        ->and(json_encode($person))->not->toContain('ana@ejemplo.com')->not->toContain('1-1111-1111')
        ->not->toContain('111111111')->not->toContain('1990-01-01')->not->toContain('CR05015202001026284066')
        ->and($person['registro']['cuenta_bancaria'])->toBe('••••4066')
        ->and($person['registro']['salario_base'])->toBe('800000.00')
        ->and(collect($notes['registros'])->pluck('titulo')->all())->toBe(['Pública']);
});

it('busca, filtra y pagina, y avisa si un filtro no existe', function () {
    ['user' => $user, 'company' => $company] = contiUser(['accounting.chart_of_accounts' => 'read']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01', 'description_es' => 'Caja general']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-02', 'description_es' => 'Bancos']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-03', 'description_es' => 'Caja chica', 'is_active' => false]);
    $tools = contiTools($user, $company);
    $query = fn (array $params) => $tools->run('consultar', ['consulta' => 'cuentas', 'parametros' => $params]);

    expect($query(['buscar' => 'caja'])['total'])->toBe(2)
        ->and($query(['buscar' => 'caja', 'activa' => true])['total'])->toBe(1)
        ->and($query(['limite' => 1, 'pagina' => 2])['registros'][0]['codigo'])->toBe('1-02')
        ->and($query(['limite' => 1, 'pagina' => 2])['hay_mas'])->toBeTrue()
        ->and($query(['color' => 'rojo'])['codigo'])->toBe(422);
});

it('corre un reporte con los mismos servicios de la pantalla, si tiene esa pantalla', function () {
    ['user' => $user, 'company' => $company] = contiUser(['reports.trial_balance' => 'read']);
    $tools = contiTools($user, $company);

    expect($tools->run('reporte', ['reporte' => 'balance-comprobacion', 'parametros' => ['desde' => '2026-01-01', 'hasta' => '2026-12-31']])['reporte'])->toBe('balance-comprobacion')
        ->and($tools->run('reporte', ['reporte' => 'estado-resultados'])['codigo'])->toBe(403)
        ->and($tools->run('reporte', ['reporte' => 'balance-comprobacion', 'parametros' => ['otro' => 1]])['codigo'])->toBe(422);
});

it('el mayor de un socio pide la pantalla de socios, aunque tenga la de cuentas', function () {
    ['user' => $user, 'company' => $company] = contiUser(['accounting.chart_of_accounts' => 'read']);
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-001', 'gl_account_id' => $account->id]);
    $tools = contiTools($user, $company);

    expect($tools->run('reporte', ['reporte' => 'mayor', 'parametros' => ['tipo' => 'cuenta', 'codigo' => '1-01-02']]))->not->toHaveKey('error')
        ->and($tools->run('reporte', ['reporte' => 'mayor', 'parametros' => ['tipo' => 'socio', 'codigo' => 'C-001']])['codigo'])->toBe(403);
});

it('de un reporte de planilla no salen las columnas personales aunque se pidan', function () {
    ['user' => $user, 'company' => $company] = contiUser(['payroll.reports' => 'read']);

    $result = contiTools($user, $company)->run('reporte', ['reporte' => 'planilla-employees', 'parametros' => ['columnas' => 'full_name,email,identification,birth_date,base_salary']]);

    expect(collect($result['resultado']['columnas'])->pluck('clave')->all())->toBe(['full_name', 'base_salary']);
});

it('el contexto da los permisos, o el detalle de una consulta, un reporte o una acción', function () {
    ['user' => $user, 'company' => $company] = contiUser(['business_partners.partners' => 'read_write']);
    $tools = contiTools($user, $company);

    expect($tools->run('contexto', [])['permisos'])->toContain('Socios de negocio → Socios de negocio (lectura y escritura)')
        ->and($tools->run('contexto', ['ver' => 'consulta', 'clave' => 'socios'])['filtros'])->toHaveKeys(['tipo', 'estado', 'categoria'])
        ->and($tools->run('contexto', ['ver' => 'accion', 'clave' => 'crear_socio'])['datos'])->toHaveKey('cuenta_control')
        ->and($tools->run('contexto', ['ver' => 'accion', 'clave' => 'crear_asiento'])['codigo'])->toBe(403);
});

it('el manual devuelve las secciones del tema que se pregunta', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    $tools = contiTools($user, $company);

    $sections = collect($tools->run('manual', ['tema' => 'anular un asiento contabilizado'])['secciones'])->pluck('seccion');

    expect($sections->first())->toContain('Registros')
        ->and($tools->run('manual', ['tema' => 'aguinaldo liquidación'])['secciones'][0]['seccion'])->toContain('Liquidaciones')
        ->and($tools->run('manual', ['tema' => ''])['codigo'])->toBe(422);
});

it('una herramienta que no existe vuelve como error, sin romper nada', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);

    expect(contiTools($user, $company)->run('borrar_todo', [])['codigo'])->toBe(404);
});
