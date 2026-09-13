<?php

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Models\User;

function permissionFixture(): array
{
    $company = Company::factory()->create();
    $superAdmin = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($superAdmin->id, ['is_default' => true]);

    $accounting = Module::factory()->create();
    $reports = Module::factory()->create();

    return compact('company', 'superAdmin', 'accounting', 'reports');
}

it('el superusuario puede crear un administrador y un usuario con cualquier nivel de acceso', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $admin = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [$fx['accounting']->id => 'read_write'],
    );

    expect($admin->roleTypeFor($fx['company']->id))->toBe('admin')
        ->and($admin->canGrantPermissionsFor($fx['company']->id))->toBeTrue()
        ->and($admin->modulePermissionFor($fx['company']->id, $fx['accounting']->id))->toBe('read_write');

    $user = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'secreto123'],
        'user', [$fx['reports']->id => 'read'],
    );

    expect($user->roleTypeFor($fx['company']->id))->toBe('user')
        ->and($user->canGrantPermissionsFor($fx['company']->id))->toBeFalse();
});

it('un administrador no puede otorgar más acceso del que él mismo tiene, y el intento queda en audit_logs', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $admin = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [$fx['accounting']->id => 'read'],
    );

    expect(fn () => $service->createUser(
        $admin, $fx['company'],
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'secreto123'],
        'user', [$fx['accounting']->id => 'read_write'],
    ))->toThrow(PrivilegeEscalationException::class);

    expect(User::where('email', 'beto@example.com')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'permission_escalation_attempt')->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('un administrador no puede crear a otro administrador', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $admin = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [],
    );

    expect(fn () => $service->createUser(
        $admin, $fx['company'],
        ['name' => 'Otro Admin', 'email' => 'otro@example.com', 'password' => 'secreto123'],
        'admin', [],
    ))->toThrow(PrivilegeEscalationException::class);
});

it('un usuario común no puede crear a nadie', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $user = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'secreto123'],
        'user', [],
    );

    expect($service->grantableRoleTypes($user, $fx['company']->id))->toBe([]);

    expect(fn () => $service->createUser(
        $user, $fx['company'],
        ['name' => 'Otro Usuario', 'email' => 'otro@example.com', 'password' => 'secreto123'],
        'user', [],
    ))->toThrow(PrivilegeEscalationException::class);
});

it('updatePermissions respeta el mismo tope de no-escalamiento', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $admin = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'secreto123'],
        'admin', [$fx['accounting']->id => 'read'],
    );
    $user = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'secreto123'],
        'user', [],
    );

    expect(fn () => $service->updatePermissions($admin, $user, $fx['company']->id, [$fx['accounting']->id => 'read_write']))
        ->toThrow(PrivilegeEscalationException::class);

    $service->updatePermissions($admin, $user, $fx['company']->id, [$fx['accounting']->id => 'read']);
    expect($user->fresh()->modulePermissionFor($fx['company']->id, $fx['accounting']->id))->toBe('read');
});

it('un administrador no puede modificar los permisos de otro administrador ni del superusuario', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $adminA = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Admin A', 'email' => 'admina@example.com', 'password' => 'secreto123'],
        'admin', [$fx['accounting']->id => 'read_write'],
    );
    $adminB = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Admin B', 'email' => 'adminb@example.com', 'password' => 'secreto123'],
        'admin', [$fx['accounting']->id => 'read_write'],
    );

    expect($service->canManage($adminA, $adminB, $fx['company']->id))->toBeFalse()
        ->and($service->canManage($adminA, $fx['superAdmin'], $fx['company']->id))->toBeFalse();
});

it('hasAtLeast: el superusuario siempre pasa, y un módulo inexistente falla cerrado', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    expect($service->hasAtLeast($fx['superAdmin'], $fx['company']->id, 'reports', 'read_write'))->toBeTrue()
        ->and($service->hasAtLeast($fx['superAdmin'], $fx['company']->id, 'un-modulo-que-no-existe', 'read'))->toBeTrue();

    $plainUser = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Beto', 'email' => 'beto2@example.com', 'password' => 'secreto123'],
        'user', [],
    );

    expect($service->hasAtLeast($plainUser, $fx['company']->id, 'un-modulo-que-no-existe', 'read'))->toBeFalse();
});

it('hasAtLeast respeta el nivel otorgado: alcanza el mínimo pedido, pero no más', function () {
    $fx = permissionFixture();
    $service = app(PermissionGrantService::class);

    $reportsModule = Module::factory()->create(['code' => 'reports']);

    $reader = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Lector', 'email' => 'lector@example.com', 'password' => 'secreto123'],
        'user', [$reportsModule->id => 'read'],
    );

    expect($service->hasAtLeast($reader, $fx['company']->id, 'reports', 'read'))->toBeTrue()
        ->and($service->hasAtLeast($reader, $fx['company']->id, 'reports', 'read_write'))->toBeFalse();

    $withoutGrant = $service->createUser(
        $fx['superAdmin'], $fx['company'],
        ['name' => 'Sin permiso', 'email' => 'sinpermiso@example.com', 'password' => 'secreto123'],
        'user', [],
    );

    expect($service->hasAtLeast($withoutGrant, $fx['company']->id, 'reports', 'read'))->toBeFalse();
});

it('los permisos de un módulo no cruzan entre compañías distintas', function () {
    $fxA = permissionFixture();
    $fxB = permissionFixture();
    $service = app(PermissionGrantService::class);

    $adminA = $service->createUser(
        $fxA['superAdmin'], $fxA['company'],
        ['name' => 'Admin A', 'email' => 'admina@example.com', 'password' => 'secreto123'],
        'admin', [$fxA['accounting']->id => 'read_write'],
    );

    expect($adminA->modulePermissionFor($fxB['company']->id, $fxB['accounting']->id))->toBe('none');
});
