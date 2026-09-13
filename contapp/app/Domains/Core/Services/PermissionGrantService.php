<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Exceptions\UserNotFoundException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Models\UserRole;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Licensing\Exceptions\LicenseQuotaExceededException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Jerarquía de delegación (CLAUDE.md secc. 12, 14, 16): Superusuario crea
 * Administradores y Usuarios; un Administrador (con can_grant_permissions)
 * solo crea Usuarios; un Usuario no delega nada. Regla no negociable
 * reforzada acá, en el servicio — nunca solo en la UI: nadie puede otorgar
 * un nivel de acceso por módulo mayor al que él mismo tiene.
 */
class PermissionGrantService
{
    private const ACCESS_LEVELS = ['none' => 0, 'read' => 1, 'read_write' => 2];

    public function effectiveAccessLevel(User $grantor, int $companyId, Module $module): string
    {
        if ($grantor->isSuperAdmin()) {
            return 'read_write';
        }

        return $grantor->modulePermissionFor($companyId, $module->id);
    }

    /**
     * Enforcement en tiempo real (a diferencia de effectiveAccessLevel(),
     * pensado para el flujo de otorgar/validar permisos): ¿$user tiene al
     * menos $minLevel en el módulo $moduleCode, en esta compañía? Un módulo
     * inexistente falla cerrado (false), mismo criterio que CompanyScope
     * ante una compañía activa ausente.
     */
    public function hasAtLeast(User $user, int $companyId, string $moduleCode, string $minLevel): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $module = Module::where('code', $moduleCode)->first();

        if ($module === null) {
            return false;
        }

        return self::ACCESS_LEVELS[$this->effectiveAccessLevel($user, $companyId, $module)] >= self::ACCESS_LEVELS[$minLevel];
    }

    /**
     * @return string[] tipos de rol que $grantor puede asignar al crear un usuario nuevo.
     */
    public function grantableRoleTypes(User $grantor, int $companyId): array
    {
        if ($grantor->isSuperAdmin()) {
            return ['admin', 'user'];
        }

        if ($grantor->canGrantPermissionsFor($companyId)) {
            return ['user'];
        }

        return [];
    }

    /**
     * Superusuario gestiona a cualquiera de su compañía. Un Administrador
     * gestiona a cualquier Usuario de la misma compañía (todos actúan "a
     * nombre del Superusuario", CLAUDE.md secc. 14 — no están aislados entre
     * sí). Nadie gestiona a un Superusuario ni a otro Administrador salvo
     * el propio Superusuario.
     */
    public function canManage(User $grantor, User $target, int $companyId): bool
    {
        if ($grantor->isSuperAdmin()) {
            return true;
        }

        return $grantor->canGrantPermissionsFor($companyId)
            && $target->roleTypeFor($companyId) === 'user';
    }

    /**
     * @param  array<int, string>  $modulePermissions  module_id => access_level
     *
     * @throws PrivilegeEscalationException
     */
    public function assertGrantAllowed(User $grantor, int $companyId, Module $module, string $accessLevel): void
    {
        $requested = self::ACCESS_LEVELS[$accessLevel] ?? null;

        if ($requested === null) {
            throw new \InvalidArgumentException("Nivel de acceso desconocido: {$accessLevel}.");
        }

        $effective = self::ACCESS_LEVELS[$this->effectiveAccessLevel($grantor, $companyId, $module)];

        if ($requested > $effective) {
            AuditLog::create([
                'company_id' => $companyId,
                'user_id' => $grantor->id,
                'action' => 'permission_escalation_attempt',
                'auditable_type' => Module::class,
                'auditable_id' => $module->id,
                'new_values' => ['requested_access_level' => $accessLevel],
                'created_at' => now(),
            ]);

            throw new PrivilegeEscalationException(
                "No podés otorgar el nivel '{$accessLevel}' del módulo '{$module->code}': no lo tenés vos mismo."
            );
        }
    }

    /**
     * Cupo de admins/usuarios de la licencia de $company (CLAUDE.md secc.
     * 13/14 ampliado). $excludeUserId: al INVITAR (a diferencia de crear
     * desde cero) el target puede ya tener ese mismo tipo de rol en otra
     * compañía de la MISMA licencia — como License::adminsCount()/
     * usersCount() cuentan personas distintas, agregarlo a una compañía más
     * no sube el conteo, así que no corresponde bloquearlo por cupo.
     */
    private function assertQuotaAvailable(Company $company, string $roleType, ?int $excludeUserId = null): void
    {
        $license = $company->license;

        if ($license === null) {
            return;
        }

        if ($excludeUserId !== null) {
            $alreadyCounted = UserRole::whereIn('company_id', $license->companies()->pluck('id'))
                ->where('user_id', $excludeUserId)
                ->whereHas('role', fn ($q) => $q->where('type', $roleType))
                ->exists();

            if ($alreadyCounted) {
                return;
            }
        }

        if ($roleType === 'admin' && ! $license->canAddAnotherAdmin()) {
            throw new LicenseQuotaExceededException("Tu licencia ya alcanzó el máximo de {$license->max_admins} administradores.");
        }

        if ($roleType === 'user' && ! $license->canAddAnotherUser()) {
            throw new LicenseQuotaExceededException("Tu licencia ya alcanzó el máximo de {$license->max_users} usuarios.");
        }
    }

    /**
     * @param  array{name: string, email: string, password: string}  $userData
     * @param  array<int, string>  $modulePermissions  module_id => access_level
     *
     * @throws LicenseQuotaExceededException
     */
    public function createUser(User $grantor, Company $company, array $userData, string $roleType, array $modulePermissions): User
    {
        if (! in_array($roleType, $this->grantableRoleTypes($grantor, $company->id), true)) {
            throw new PrivilegeEscalationException("No podés crear un usuario de tipo '{$roleType}'.");
        }

        $this->assertQuotaAvailable($company, $roleType);

        $modules = Module::whereIn('id', array_keys($modulePermissions))->get()->keyBy('id');

        foreach ($modulePermissions as $moduleId => $accessLevel) {
            $this->assertGrantAllowed($grantor, $company->id, $modules[$moduleId], $accessLevel);
        }

        return DB::transaction(function () use ($grantor, $company, $userData, $roleType, $modulePermissions, $modules) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'default_company_id' => $company->id,
                'status' => 'active',
            ]);

            $company->users()->attach($user->id, ['is_default' => true]);

            $role = Role::firstOrCreate(
                ['company_id' => null, 'type' => $roleType],
                [
                    'name' => $roleType === 'admin' ? 'Administrador' : 'Usuario',
                    'can_grant_permissions' => $roleType === 'admin',
                ]
            );

            $user->userRoles()->create(['role_id' => $role->id, 'company_id' => $company->id]);

            foreach ($modulePermissions as $moduleId => $accessLevel) {
                ModulePermission::create([
                    'company_id' => $company->id,
                    'module_id' => $moduleId,
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'access_level' => $accessLevel,
                ]);
            }

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $grantor->id,
                'action' => 'user_created',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => ['role_type' => $roleType, 'module_permissions' => $modulePermissions],
                'created_at' => now(),
            ]);

            return $user;
        });
    }

    /**
     * Vincula una cuenta YA EXISTENTE (identificada por email) a esta
     * compañía, en vez de crear una fila nueva en `users` — es lo que
     * permite que la misma persona participe en varias cuentas con licencia
     * sin tener credenciales distintas para cada una (CLAUDE.md secc. 12/14
     * ampliado: identidad no amarrada a una sola licencia). Nunca toca
     * password/name/email de la cuenta existente.
     *
     * @param  array<int, string>  $modulePermissions  module_id => access_level
     *
     * @throws UserNotFoundException
     * @throws PrivilegeEscalationException
     */
    public function inviteUser(User $grantor, Company $company, string $email, string $roleType, array $modulePermissions): User
    {
        $existing = User::where('email', $email)->first();

        if (! $existing || $existing->status !== 'active') {
            throw new UserNotFoundException("No existe ninguna cuenta activa con el correo {$email}. Si es una persona nueva, usá \"Crear usuario\" en vez de invitar.");
        }

        if (! in_array($roleType, $this->grantableRoleTypes($grantor, $company->id), true)) {
            throw new PrivilegeEscalationException("No podés invitar un usuario de tipo '{$roleType}'.");
        }

        $this->assertQuotaAvailable($company, $roleType, $existing->id);

        $modules = Module::whereIn('id', array_keys($modulePermissions))->get()->keyBy('id');

        foreach ($modulePermissions as $moduleId => $accessLevel) {
            $this->assertGrantAllowed($grantor, $company->id, $modules[$moduleId], $accessLevel);
        }

        return DB::transaction(function () use ($grantor, $company, $existing, $roleType, $modulePermissions) {
            // syncWithoutDetaching: si la persona ya pertenecía a esta
            // compañía (edge case improbable pero posible), no duplica la
            // fila ni pisa su is_default existente.
            $company->users()->syncWithoutDetaching([$existing->id => ['is_default' => false]]);

            $role = Role::firstOrCreate(
                ['company_id' => null, 'type' => $roleType],
                [
                    'name' => $roleType === 'admin' ? 'Administrador' : 'Usuario',
                    'can_grant_permissions' => $roleType === 'admin',
                ]
            );

            $existing->userRoles()->firstOrCreate(['role_id' => $role->id, 'company_id' => $company->id]);

            foreach ($modulePermissions as $moduleId => $accessLevel) {
                ModulePermission::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'module_id' => $moduleId, 'subject_type' => 'user', 'subject_id' => $existing->id],
                    ['access_level' => $accessLevel]
                );
            }

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $grantor->id,
                'action' => 'user_invited',
                'auditable_type' => User::class,
                'auditable_id' => $existing->id,
                'new_values' => ['role_type' => $roleType, 'module_permissions' => $modulePermissions],
                'created_at' => now(),
            ]);

            return $existing;
        });
    }

    /**
     * @param  array<int, string>  $modulePermissions  module_id => access_level
     */
    public function updatePermissions(User $grantor, User $target, int $companyId, array $modulePermissions): void
    {
        if (! $this->canManage($grantor, $target, $companyId)) {
            throw new PrivilegeEscalationException('No podés modificar los permisos de este usuario.');
        }

        $modules = Module::whereIn('id', array_keys($modulePermissions))->get()->keyBy('id');

        foreach ($modulePermissions as $moduleId => $accessLevel) {
            $this->assertGrantAllowed($grantor, $companyId, $modules[$moduleId], $accessLevel);
        }

        // withoutGlobalScope en las dos consultas siguientes: este servicio
        // debe funcionar sin CurrentCompany ambiental (ver
        // User::modulePermissionFor) — sin el bypass, updateOrCreate() jamás
        // encuentra la fila existente (CompanyScope la esconde) e intenta
        // un INSERT duplicado, chocando contra la unique key.
        $oldValues = ModulePermission::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('subject_type', 'user')
            ->where('subject_id', $target->id)
            ->pluck('access_level', 'module_id');

        DB::transaction(function () use ($target, $companyId, $modulePermissions) {
            foreach ($modulePermissions as $moduleId => $accessLevel) {
                ModulePermission::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $companyId, 'module_id' => $moduleId, 'subject_type' => 'user', 'subject_id' => $target->id],
                    ['access_level' => $accessLevel]
                );
            }
        });

        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $grantor->id,
            'action' => 'permission_change',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'old_values' => $oldValues->toArray(),
            'new_values' => $modulePermissions,
            'created_at' => now(),
        ]);
    }
}
