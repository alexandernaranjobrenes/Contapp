<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Models\User;

function grantAccountingAccess(User $user, Company $company, string $accessLevel): void
{
    $module = Module::firstOrCreate(['code' => 'accounting'], ['name' => 'Contabilidad (asientos, catálogo, cierres)']);

    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $module->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => $accessLevel,
    ]);
}

it('bloquea las pantallas de contabilidad a un usuario sin permiso en el módulo accounting', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('chart-of-accounts.index'))->assertForbidden();
    $this->get(route('journal-entries.index'))->assertForbidden();
    $this->get(route('cost-centers.index'))->assertForbidden();
    $this->get(route('period-close.index'))->assertForbidden();
    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();
});

it('un usuario con solo "read" puede consultar contabilidad pero no crear/modificar', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantAccountingAccess($user, $company, 'read');
    $this->actingAs($user);

    $this->get(route('chart-of-accounts.index'))->assertOk();
    $this->get(route('journal-entries.index'))->assertOk();
    $this->get(route('cost-centers.index'))->assertOk();
    $this->get(route('period-close.index'))->assertOk();

    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();
    $this->post(route('journal-entries.store'), [])->assertForbidden();
    $this->post(route('cost-centers.store'), [])->assertForbidden();
});

it('un usuario con "read_write" puede llegar a las acciones de creación (sin el 403 del gate)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantAccountingAccess($user, $company, 'read_write');
    $this->actingAs($user);

    $response = $this->post(route('chart-of-accounts.store'), []);

    expect($response->status())->not->toBe(403);
});

it('un superusuario entra a contabilidad sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('chart-of-accounts.index'))->assertOk();
    $this->get(route('journal-entries.index'))->assertOk();
});
