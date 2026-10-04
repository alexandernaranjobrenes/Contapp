<?php

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Permisos por pantalla del menú
|--------------------------------------------------------------------------
|
| Cada opción del menú tiene su nivel (ScreenCatalog). El permiso por
| módulo, el esquema anterior, sigue valiendo para todas las pantallas de
| ese módulo (ScreenAccessService).
|
*/

/** Una cuenta de la compañía con estos niveles por pantalla, con la sesión iniciada. */
function screenUser(array $levels): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id, 'status' => 'active']);
    $company->users()->attach($user->id, ['is_default' => true]);

    foreach (['accounting', 'reports', 'billing', 'tax', 'inventory', 'banking', 'business_partners', 'payroll'] as $code) {
        Module::firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
    }

    app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, $levels);
    test()->actingAs($user);

    return compact('company', 'user');
}

it('con Lectura en Registros consulta los asientos, pero no los crea ni ve otras pantallas de Contabilidad', function () {
    screenUser(['accounting.journal_entries' => 'read']);

    $this->get(route('journal-entries.index'))->assertOk();
    $this->get(route('cost-centers.index'))->assertForbidden();
    $this->get(route('chart-of-accounts.index'))->assertForbidden();
    $this->post(route('journal-entries.store'), [])->assertForbidden();
});

it('con Lectura y escritura en Registros llega a crear asientos', function () {
    screenUser(['accounting.journal_entries' => 'read_write']);

    expect($this->post(route('journal-entries.store'), [])->status())->not->toBe(403);
    $this->post(route('cost-centers.store'), [])->assertForbidden();
});

it('cada reporte es su propia pantalla', function () {
    screenUser(['reports.trial_balance' => 'read']);

    $this->get(route('reports.trial-balance.index'))->assertOk();
    $this->get(route('reports.income-statement.index'))->assertForbidden();
    $this->get(route('journal-entries.index'))->assertForbidden();
});

it('Comprobantes y Nueva factura son pantallas distintas', function () {
    screenUser(['billing.sales_documents' => 'read']);

    $this->get(route('sales-documents.index'))->assertOk();
    $this->get(route('sales-documents.create'))->assertForbidden();
});

it('el catálogo de impuestos se consulta sin permiso, pero editar los indicadores propios pide su pantalla', function () {
    ['user' => $user, 'company' => $company] = screenUser([]);

    $this->get(route('tax-rates.index'))->assertOk();
    $this->post(route('tax-rates.store'), [])->assertForbidden();

    app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, ['tax.tax_rates' => 'read_write']);

    expect($this->post(route('tax-rates.store'), [])->status())->not->toBe(403);
});

it('el permiso por módulo (el esquema anterior) vale para todas las pantallas de ese módulo', function () {
    ['user' => $user, 'company' => $company] = screenUser([]);
    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => Module::where('code', 'accounting')->value('id'),
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => 'read',
    ]);

    $levels = app(ScreenAccessService::class)->levelsFor($user, $company->id);

    expect($levels['accounting.journal_entries'])->toBe('read')
        ->and($levels['accounting.cost_centers'])->toBe('read')
        ->and($levels['reports.trial_balance'])->toBe('none');

    $this->get(route('cost-centers.index'))->assertOk();
});

it('el menú recibe el nivel de cada pantalla', function () {
    screenUser(['accounting.journal_entries' => 'read_write', 'inventory.items' => 'read']);

    // Las claves llevan punto («accounting.journal_entries»): se leen del
    // arreglo entero, no como ruta anidada.
    $screens = $this->get(route('dashboard'))->viewData('page')['props']['screenAccess'];
    $modules = $this->get(route('dashboard'))->viewData('page')['props']['moduleAccess'];

    expect($screens['accounting.journal_entries'])->toBe('read_write')
        ->and($screens['inventory.items'])->toBe('read')
        ->and($screens['accounting.cost_centers'])->toBe('none')
        ->and($modules['accounting'])->toBe('read_write')
        ->and($modules['inventory'])->toBe('read')
        ->and($modules['payroll'])->toBe('none');
});

it('un reporte no pasa de Lectura, ni siquiera para el Superusuario', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $screens = $this->get(route('dashboard'))->viewData('page')['props']['screenAccess'];

    expect($screens['reports.trial_balance'])->toBe('read')
        ->and($screens['accounting.journal_entries'])->toBe('read_write');
});

it('no se da escritura en una pantalla de solo consulta, ni más de lo que se tiene', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $grants = app(PermissionGrantService::class);
    $admin = $grants->createUser($superAdmin, $company, ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $grants->writeScreenLevels($company->id, $admin, ['accounting.journal_entries' => 'read']);

    expect(fn () => $grants->validateScreenLevels($superAdmin, $company->id, ['reports.trial_balance' => 'read_write']))
        ->toThrow(PrivilegeEscalationException::class);

    expect(fn () => $grants->validateScreenLevels($admin, $company->id, ['accounting.journal_entries' => 'read_write']))
        ->toThrow(PrivilegeEscalationException::class);

    expect($grants->validateScreenLevels($admin, $company->id, ['accounting.journal_entries' => 'read'])['accounting.journal_entries'])->toBe('read');
});

it('un administrador guarda los permisos de alguien que tiene en una pantalla más de lo que él puede dar, sin quitárselo', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $grants = app(PermissionGrantService::class);
    $admin = $grants->createUser($superAdmin, $company, ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $user = $grants->createUser($superAdmin, $company, ['name' => 'Beto', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'], 'user', []);
    $grants->writeScreenLevels($company->id, $admin, ['inventory.items' => 'read_write']);
    $grants->writeScreenLevels($company->id, $user, ['payroll.employees' => 'read_write']);

    $grants->updateScreenPermissions($admin, $user, $company->id, [
        'payroll.employees' => 'read_write',
        'inventory.items' => 'read',
    ]);

    $levels = app(ScreenAccessService::class)->levelsFor($user, $company->id);
    expect($levels['payroll.employees'])->toBe('read_write')
        ->and($levels['inventory.items'])->toBe('read');
});
