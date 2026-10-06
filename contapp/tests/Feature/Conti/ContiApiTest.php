<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Models\ContiToken;
use App\Domains\Licensing\Models\License;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| API de Conti: quién entra y qué ve
|--------------------------------------------------------------------------
|
| El agente de n8n consulta con el pase que CONTAPP le da por mensaje. Ve lo
| que la persona puede ver en el menú, nada de otra compañía, y nunca datos
| sensibles.
|
*/

it('sin pase, con uno inventado o con uno vencido, no entra', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);

    $this->getJson('/api/conti/contexto')->assertUnauthorized();
    $this->getJson('/api/conti/contexto', contiHeaders('conti_inventado'))->assertUnauthorized();

    $token = contiToken($user, $company);
    ContiToken::query()->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/conti/contexto', contiHeaders($token))->assertUnauthorized();
});

it('el pase guarda solo su hash', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);

    $token = contiToken($user, $company);

    expect(ContiToken::first()->token_hash)->toBe(hash('sha256', $token))
        ->and(ContiToken::where('token_hash', $token)->exists())->toBeFalse();
});

it('el contexto dice quién es, en qué compañía y qué puede hacer según sus permisos', function () {
    ['user' => $user, 'company' => $company] = contiUser(['accounting.journal_entries' => 'read', 'reports.trial_balance' => 'read']);

    $response = $this->getJson('/api/conti/contexto', contiHeaders(contiToken($user, $company)))->assertOk();

    expect($response->json('persona.rol'))->toBe('Usuario')
        ->and($response->json('compania.nombre'))->toBe($company->trade_name)
        ->and(collect($response->json('consultas'))->contains(fn ($c) => str_starts_with($c, 'asientos:')))->toBeTrue()
        ->and(collect($response->json('consultas'))->contains(fn ($c) => str_starts_with($c, 'empleados:')))->toBeFalse()
        ->and(collect($response->json('reportes'))->contains(fn ($c) => str_starts_with($c, 'balance-comprobacion:')))->toBeTrue()
        ->and(collect($response->json('reportes'))->contains(fn ($c) => str_starts_with($c, 'estado-resultados:')))->toBeFalse()
        // Lectura no alcanza para guardar.
        ->and($response->json('acciones'))->toBe([]);
});

it('una persona suspendida, o una compañía con la licencia revocada, ya no entra', function () {
    $license = License::factory()->create();
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    $company->update(['license_id' => $license->id]);
    $license->update(['superuser_id' => $user->id]);
    $token = contiToken($user, $company);

    $this->getJson('/api/conti/contexto', contiHeaders($token))->assertOk();

    $license->update(['status' => 'revoked']);
    $this->getJson('/api/conti/contexto', contiHeaders($token))->assertForbidden();

    $license->update(['status' => 'active']);
    $company->users()->updateExistingPivot($user->id, ['status' => 'suspended']);
    $this->getJson('/api/conti/contexto', contiHeaders($token))->assertForbidden();
});

it('consulta un conjunto que su pantalla le da, y no uno que no', function () {
    ['user' => $user, 'company' => $company] = contiUser(['business_partners.partners' => 'read']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-100', 'name' => 'Ferretería Central']);
    $headers = contiHeaders(contiToken($user, $company));

    $this->getJson('/api/conti/datos/socios', $headers)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('registros.0.codigo', 'C-100');

    $this->getJson('/api/conti/datos/empleados', $headers)
        ->assertForbidden()
        ->assertJsonFragment(['message' => 'No tenés permiso para esto: hace falta Lectura en «Planillas → Empleados» o «Planillas → Acciones de personal» o «Planillas → Vacaciones» o «Planillas → Liquidaciones laborales» o «Planillas → Períodos de planilla» o «Planillas → Rubros fijos» o «Planillas → Deducciones y préstamos» o «Planillas → Reportes de planilla». Pedíselo a quien administra la compañía.']);
});

it('nunca ve datos de otra compañía, ni por id', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    ['company' => $other] = contiUser([], null, true);
    $foreign = BusinessPartner::factory()->create(['company_id' => $other->id, 'code' => 'C-999']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-001']);
    $headers = contiHeaders(contiToken($user, $company));

    $codes = collect($this->getJson('/api/conti/datos/socios', $headers)->json('registros'))->pluck('codigo');

    expect($codes->all())->toBe(['C-001']);
    $this->getJson("/api/conti/datos/socios?id={$foreign->id}", $headers)->assertNotFound();
    $this->getJson('/api/conti/datos/socios?id=C-999', $headers)->assertNotFound();
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
    $headers = contiHeaders(contiToken($user, $company));

    $partners = $this->getJson('/api/conti/datos/socios?id=C-001', $headers)->assertOk()->getContent();
    $employees = $this->getJson('/api/conti/datos/empleados?id=E-001', $headers)->assertOk();
    $notes = $this->getJson('/api/conti/datos/anotaciones-empleado', $headers)->assertOk();

    expect($partners)->not->toContain('cliente@ejemplo.com')->not->toContain('8888-8888')
        ->and($employees->getContent())->not->toContain('ana@ejemplo.com')->not->toContain('1-1111-1111')
        ->not->toContain('111111111')->not->toContain('1990-01-01')->not->toContain('CR05015202001026284066')
        ->and($employees->json('registro.cuenta_bancaria'))->toBe('••••4066')
        ->and($employees->json('registro.salario_base'))->toBe('800000.00')
        ->and(collect($notes->json('registros'))->pluck('titulo')->all())->toBe(['Pública']);
});

it('busca, filtra y pagina, y avisa si un filtro no existe', function () {
    ['user' => $user, 'company' => $company] = contiUser(['accounting.chart_of_accounts' => 'read']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01', 'description_es' => 'Caja general']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-02', 'description_es' => 'Bancos']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-03', 'description_es' => 'Caja chica', 'is_active' => false]);
    $headers = contiHeaders(contiToken($user, $company));

    $this->getJson('/api/conti/datos/cuentas?buscar=caja', $headers)->assertJsonPath('total', 2);
    $this->getJson('/api/conti/datos/cuentas?buscar=caja&activa=sí', $headers)->assertJsonPath('total', 1);
    $this->getJson('/api/conti/datos/cuentas?limite=1&pagina=2', $headers)
        ->assertJsonPath('registros.0.codigo', '1-02')
        ->assertJsonPath('hay_mas', true);
    $this->getJson('/api/conti/datos/cuentas?color=rojo', $headers)->assertStatus(422);
});

it('corre un reporte con los mismos servicios de la pantalla, si tiene esa pantalla', function () {
    ['user' => $user, 'company' => $company] = contiUser(['reports.trial_balance' => 'read']);
    $headers = contiHeaders(contiToken($user, $company));

    $this->getJson('/api/conti/reportes/balance-comprobacion?desde=2026-01-01&hasta=2026-12-31', $headers)
        ->assertOk()
        ->assertJsonPath('reporte', 'balance-comprobacion');

    $this->getJson('/api/conti/reportes/estado-resultados', $headers)->assertForbidden();
    $this->getJson('/api/conti/reportes/balance-comprobacion?otro=1', $headers)->assertStatus(422);
});

it('el mayor de un socio pide la pantalla de socios, aunque tenga la de cuentas', function () {
    ['user' => $user, 'company' => $company] = contiUser(['accounting.chart_of_accounts' => 'read']);
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02']);
    BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'C-001', 'gl_account_id' => $account->id]);
    $headers = contiHeaders(contiToken($user, $company));

    $this->getJson('/api/conti/reportes/mayor?tipo=cuenta&codigo=1-01-02', $headers)->assertOk();
    $this->getJson('/api/conti/reportes/mayor?tipo=socio&codigo=C-001', $headers)->assertForbidden();
});

it('de un reporte de planilla no salen las columnas personales aunque se pidan', function () {
    ['user' => $user, 'company' => $company] = contiUser(['payroll.reports' => 'read']);
    $headers = contiHeaders(contiToken($user, $company));

    $columns = collect($this->getJson('/api/conti/reportes/planilla-employees?columnas=full_name,email,identification,birth_date,base_salary', $headers)
        ->assertOk()->json('resultado.columnas'))->pluck('clave')->all();

    expect($columns)->toBe(['full_name', 'base_salary']);
});

it('al cerrar sesión, sus pases dejan de servir', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    $token = contiToken($user, $company);

    $this->actingAs($user)->delete(route('logout'));

    $this->getJson('/api/conti/contexto', contiHeaders($token))->assertUnauthorized();
});
