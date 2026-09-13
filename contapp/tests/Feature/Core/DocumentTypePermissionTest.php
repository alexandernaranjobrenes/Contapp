<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Models\UserRole;
use App\Models\User;

it('resuelve permisos otorgados directamente a un usuario', function () {
    $company = Company::factory()->create();
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);
    $user = User::factory()->create();

    $documentType->permissions()->create([
        'subject_type' => 'user',
        'subject_id' => $user->id,
        'scope' => 'individual',
        'can_create' => true,
        'can_void' => false,
    ]);

    expect($documentType->userCan($user, 'can_create'))->toBeTrue()
        ->and($documentType->userCan($user, 'can_void'))->toBeFalse();
});

it('resuelve permisos otorgados a un rol (grupal)', function () {
    $company = Company::factory()->create();
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $user = User::factory()->create();

    $role = Role::create(['company_id' => $company->id, 'name' => 'Vendedores', 'type' => 'user']);
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'company_id' => $company->id]);

    $documentType->permissions()->create([
        'subject_type' => 'role',
        'subject_id' => $role->id,
        'scope' => 'group',
        'can_create' => true,
    ]);

    expect($documentType->userCan($user, 'can_create'))->toBeTrue();
});

it('el super usuario tiene todos los permisos sin necesidad de configuración', function () {
    $company = Company::factory()->create();
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ACC']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    expect($documentType->userCan($superAdmin, 'can_delete'))->toBeTrue();
});

it('un usuario sin permiso configurado no puede operar el documento', function () {
    $company = Company::factory()->create();
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'NCC']);
    $user = User::factory()->create();

    expect($documentType->userCan($user, 'can_create'))->toBeFalse();
});
