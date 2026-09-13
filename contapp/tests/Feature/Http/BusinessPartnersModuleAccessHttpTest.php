<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Models\User;

function grantBusinessPartnersAccess(User $user, Company $company, string $accessLevel): void
{
    $module = Module::firstOrCreate(['code' => 'business_partners'], ['name' => 'Socios de negocio y cartera']);

    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $module->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => $accessLevel,
    ]);
}

it('bloquea las pantallas de socios de negocio a un usuario sin permiso en el módulo business_partners', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('business-partners.index'))->assertForbidden();
    $this->get(route('bp-categories.index'))->assertForbidden();
    $this->post(route('business-partners.store'), [])->assertForbidden();
});

it('un usuario con solo "read" puede consultar socios de negocio pero no crear/modificar', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantBusinessPartnersAccess($user, $company, 'read');
    $this->actingAs($user);

    $this->get(route('business-partners.index'))->assertOk();
    $this->get(route('bp-categories.index'))->assertOk();

    $this->post(route('business-partners.store'), [])->assertForbidden();
    $this->post(route('bp-categories.store'), [])->assertForbidden();
});

it('un usuario con "read_write" puede llegar a las acciones de creación (sin el 403 del gate)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantBusinessPartnersAccess($user, $company, 'read_write');
    $this->actingAs($user);

    $response = $this->post(route('business-partners.store'), []);

    expect($response->status())->not->toBe(403);
});

it('un superusuario entra a socios de negocio sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('business-partners.index'))->assertOk();
});
