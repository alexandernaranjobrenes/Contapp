<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Models\User;

function grantBankingAccess(User $user, Company $company, string $accessLevel): void
{
    $module = Module::firstOrCreate(['code' => 'banking'], ['name' => 'Bancos y conciliaciones']);

    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $module->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => $accessLevel,
    ]);
}

it('bloquea las pantallas de bancos a un usuario sin permiso en el módulo banking', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('bank-accounts.index'))->assertForbidden();
    $this->get(route('bank-reconciliations.hub'))->assertForbidden();
    $this->post(route('bank-accounts.store'), [])->assertForbidden();
});

it('un usuario con solo "read" puede consultar bancos pero no crear/modificar', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantBankingAccess($user, $company, 'read');
    $this->actingAs($user);

    $this->get(route('bank-accounts.index'))->assertOk();
    $this->get(route('bank-reconciliations.hub'))->assertOk();

    $this->post(route('bank-accounts.store'), [])->assertForbidden();
});

it('un usuario con "read_write" puede llegar a las acciones de creación (sin el 403 del gate)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantBankingAccess($user, $company, 'read_write');
    $this->actingAs($user);

    $response = $this->post(route('bank-accounts.store'), []);

    expect($response->status())->not->toBe(403);
});

it('un superusuario entra a bancos sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('bank-accounts.index'))->assertOk();
});
