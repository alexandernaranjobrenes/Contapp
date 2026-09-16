<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Models\User;

function inventoryUser(Company $company, ?string $accessLevel = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    if ($accessLevel) {
        $module = Module::firstOrCreate(['code' => 'inventory'], ['name' => 'Inventario (artículos, almacenes)']);

        ModulePermission::create([
            'company_id' => $company->id, 'module_id' => $module->id,
            'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => $accessLevel,
        ]);
    }

    return $user;
}

it('bloquea los catálogos de inventario a un usuario sin permiso en el módulo', function () {
    $company = Company::factory()->create();
    $this->actingAs(inventoryUser($company));

    $this->get(route('items.index'))->assertForbidden();
    $this->get(route('warehouses.index'))->assertForbidden();
    $this->get(route('item-groups.index'))->assertForbidden();
    $this->get(route('units-of-measure.index'))->assertForbidden();
});

it('un usuario con read consulta los catálogos pero no puede escribir', function () {
    $company = Company::factory()->create();
    $this->actingAs(inventoryUser($company, 'read'));

    $this->get(route('items.index'))->assertOk();

    $this->post(route('units-of-measure.store'), [
        'code' => 'UND', 'name' => 'Unidad', 'decimals' => 0, 'status' => 'active',
    ])->assertForbidden();

    expect(UnitOfMeasure::withoutGlobalScopes()->count())->toBe(0);
});

it('un usuario con read_write sí puede crear', function () {
    $company = Company::factory()->create();
    $this->actingAs(inventoryUser($company, 'read_write'));

    $this->post(route('units-of-measure.store'), [
        'code' => 'UND', 'name' => 'Unidad', 'decimals' => 0, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(UnitOfMeasure::where('company_id', $company->id)->count())->toBe(1);
});

it('un superusuario entra sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('items.index'))->assertOk();
});
