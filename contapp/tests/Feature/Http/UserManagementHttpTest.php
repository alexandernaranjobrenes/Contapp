<?php

use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ScreenPermission;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => Notification::fake());

it('rechaza el acceso a /users a un usuario común sin permisos delegados', function () {
    logInAsCompanyUser();

    $this->get(route('users.index'))->assertForbidden();
});

it('un superusuario ve los usuarios e invita a una persona con permisos por pantalla, sin crear su cuenta', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Users/Index'));

    $this->get(route('users.create'))
        ->assertInertia(fn ($page) => $page->component('Users/Create')
            ->has('sections', 8)
            ->where('sections.0.label', 'Contabilidad')
            ->where('sections.0.screens.0.key', 'accounting.journal_entries')
            ->where('sections.0.screens.0.max_level', 'read_write'));

    $this->post(route('users.store'), [
        'email' => 'Ana@Example.com',
        'role_type' => 'admin',
        'permissions' => ['accounting.journal_entries' => 'read_write', 'reports.trial_balance' => 'read'],
    ])->assertRedirect(route('users.index'))->assertSessionHas('success');

    // Ni la cuenta ni el acceso existen hasta que acepte.
    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();

    $invitation = CompanyInvitation::sole();
    expect($invitation->company_id)->toBe($company->id)
        ->and($invitation->email)->toBe('ana@example.com')
        ->and($invitation->role_type)->toBe('admin')
        ->and($invitation->screen_permissions)->toBe(['accounting.journal_entries' => 'read_write', 'reports.trial_balance' => 'read']);
});

it('rechaza vía HTTP que un administrador invite con más acceso del que tiene, sin crear nada', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $module = Module::where('code', 'accounting')->firstOrFail();

    $admin = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [$module->id => 'read'],
    );

    $this->actingAs($admin);
    $this->post(route('users.store'), [
        'email' => 'beto@example.com',
        'role_type' => 'user',
        'permissions' => ['accounting.journal_entries' => 'read_write'],
    ])->assertSessionHasErrors('permissions');

    expect(CompanyInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

it('un administrador no invita a otro administrador', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $admin = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [],
    );

    $this->actingAs($admin);
    $this->post(route('users.store'), ['email' => 'beto@example.com', 'role_type' => 'admin', 'permissions' => []])
        ->assertSessionHasErrors('role_type');

    expect(CompanyInvitation::count())->toBe(0);
});

it('un administrador edita los permisos por pantalla de un usuario dentro de su propio tope', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $module = Module::where('code', 'accounting')->firstOrFail();

    $service = app(PermissionGrantService::class);
    $admin = $service->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [$module->id => 'read_write'],
    );
    $user = $service->createUser(
        $superAdmin, $company,
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'],
        'user', [$module->id => 'read'],
    );

    $this->actingAs($admin);

    // Sus permisos por módulo se ven ya repartidos en pantallas.
    $this->get(route('users.permissions.edit', $user->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Users/Permissions')
            ->where('sections.0.screens.0.current_level', 'read')
            ->where('sections.0.screens.0.max_level', 'read_write'));

    $this->put(route('users.permissions.update', $user->id), [
        'permissions' => ['accounting.journal_entries' => 'read_write', 'accounting.cost_centers' => 'read'],
    ])->assertRedirect(route('users.index'));

    $levels = app(ScreenAccessService::class)->levelsFor($user->fresh(), $company->id);

    expect($levels['accounting.journal_entries'])->toBe('read_write')
        ->and($levels['accounting.cost_centers'])->toBe('read')
        // El permiso por módulo se reemplazó: lo que no se marcó quedó sin acceso.
        ->and($levels['accounting.chart_of_accounts'])->toBe('none')
        ->and($user->fresh()->modulePermissionFor($company->id, $module->id))->toBe('none')
        ->and(ScreenPermission::where('user_id', $user->id)->count())->toBe(2);
});

it('ya no existe la ruta que vinculaba una cuenta sin que la persona aceptara', function () {
    expect(Route::has('users.invite'))->toBeFalse();
});
