<?php

use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Models\User;

it('rechaza el acceso a /users a un usuario común sin permisos delegados', function () {
    logInAsCompanyUser();

    $this->get(route('users.index'))->assertForbidden();
});

it('un superusuario ve y crea usuarios en su compañía', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    // logInAsCompanyUser() ya crea el módulo "accounting" (grantAllModuleAccess);
    // reutilizarlo en vez de Module::factory()->create() evita chocar contra
    // la unique key de modules.code.
    $module = Module::where('code', 'accounting')->firstOrFail();

    $this->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Users/Index'));

    $this->post(route('users.store'), [
        'name' => 'Ana Admin',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'role_type' => 'admin',
        'permissions' => [$module->id => 'read_write'],
    ])->assertRedirect(route('users.index'));

    $admin = User::where('email', 'ana@example.com')->sole();
    expect($admin->roleTypeFor($company->id))->toBe('admin')
        ->and($admin->modulePermissionFor($company->id, $module->id))->toBe('read_write');
});

it('rechaza vía HTTP que un administrador otorgue más acceso del que tiene, sin crear nada', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    // logInAsCompanyUser() ya crea el módulo "accounting" (grantAllModuleAccess);
    // reutilizarlo en vez de Module::factory()->create() evita chocar contra
    // la unique key de modules.code.
    $module = Module::where('code', 'accounting')->firstOrFail();

    $admin = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [$module->id => 'read'],
    );

    $this->actingAs($admin);
    $this->post(route('users.store'), [
        'name' => 'Beto Usuario',
        'email' => 'beto@example.com',
        'password' => 'secreto123',
        'role_type' => 'user',
        'permissions' => [$module->id => 'read_write'],
    ])->assertSessionHasErrors('permissions');

    expect(User::where('email', 'beto@example.com')->exists())->toBeFalse();
});

it('un administrador puede editar los permisos de un usuario dentro de su propio tope', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    // logInAsCompanyUser() ya crea el módulo "accounting" (grantAllModuleAccess);
    // reutilizarlo en vez de Module::factory()->create() evita chocar contra
    // la unique key de modules.code.
    $module = Module::where('code', 'accounting')->firstOrFail();

    $service = app(PermissionGrantService::class);
    $admin = $service->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [$module->id => 'read_write'],
    );
    $user = $service->createUser(
        $superAdmin, $company,
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'secreto123'],
        'user', [],
    );

    $this->actingAs($admin);

    $this->get(route('users.permissions.edit', $user->id))->assertOk();

    $this->put(route('users.permissions.update', $user->id), [
        'permissions' => [$module->id => 'read'],
    ])->assertRedirect(route('users.index'));

    expect($user->fresh()->modulePermissionFor($company->id, $module->id))->toBe('read');
});
