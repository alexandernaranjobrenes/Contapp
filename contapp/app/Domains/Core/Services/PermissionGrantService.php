<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Exceptions\UserNotFoundException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Models\ScreenPermission;
use App\Domains\Core\Models\UserRole;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Licensing\Exceptions\LicenseQuotaExceededException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        if ($grantor->isSuperAdmin($companyId)) {
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
        if ($user->isSuperAdmin($companyId)) {
            return true;
        }

        if (! Module::where('code', $moduleCode)->exists()) {
            return false;
        }

        // El permiso del módulo o el de cualquiera de sus pantallas (ver
        // ScreenAccessService::moduleLevel). La pantalla puntual de la ruta la
        // revisa EnsureModuleAccess después.
        $level = app(ScreenAccessService::class)->moduleLevel($user, $companyId, $moduleCode);

        return self::ACCESS_LEVELS[$level] >= self::ACCESS_LEVELS[$minLevel];
    }

    /**
     * @return string[] tipos de rol que $grantor puede asignar al crear un usuario nuevo.
     */
    public function grantableRoleTypes(User $grantor, int $companyId): array
    {
        if ($grantor->isSuperAdmin($companyId)) {
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
        if ($grantor->isSuperAdmin($companyId)) {
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
     * ¿Hay lugar en la licencia de $company para una persona más con este
     * rol? Cuenta también las invitaciones que todavía se pueden aceptar
     * (CompanyInvitationService); si no, se podría invitar o ascender a más
     * gente de la que entra. Quien ya ocupa ese rol en otra compañía de la
     * misma licencia no suma.
     *
     * @throws LicenseQuotaExceededException
     */
    public function assertSeatAvailable(Company $company, string $roleType, ?User $account = null, ?string $email = null, ?int $exceptInvitationId = null): void
    {
        $license = $company->license;

        if ($license === null) {
            return;
        }

        $companyIds = $license->companies()->pluck('id');

        if ($account !== null) {
            $alreadyCounted = UserRole::whereIn('company_id', $companyIds)
                ->where('user_id', $account->id)
                ->whereHas('role', fn ($q) => $q->where('type', $roleType))
                ->exists();

            if ($alreadyCounted) {
                return;
            }
        }

        $email ??= $account?->email;

        $pending = CompanyInvitation::pending()
            ->whereIn('company_id', $companyIds)
            ->where('role_type', $roleType)
            ->when($email !== null, fn ($q) => $q->where('email', '!=', Str::lower($email)))
            ->when($exceptInvitationId, fn ($q) => $q->whereKeyNot($exceptInvitationId))
            ->distinct()
            ->count('email');

        [$used, $max, $label] = $roleType === 'admin'
            ? [$license->adminsCount(), $license->max_admins, 'administradores']
            : [$license->usersCount(), $license->max_users, 'usuarios'];

        if (! $license->isValid() || $used + $pending >= $max) {
            throw new LicenseQuotaExceededException(
                "Tu licencia ya alcanzó el máximo de {$max} {$label}".($pending ? ", contando {$pending} invitación(es) sin aceptar" : '').'.'
            );
        }
    }

    /**
     * El Superusuario convierte a un Usuario en Administrador, o al revés,
     * en esta compañía. Sus permisos por pantalla no cambian; lo que cambia
     * es si puede invitar y gestionar Usuarios (CLAUDE.md secc. 12).
     *
     * @throws PrivilegeEscalationException
     * @throws LicenseQuotaExceededException
     */
    public function changeRole(User $grantor, User $target, Company $company, string $roleType): void
    {
        if (! in_array($roleType, ['admin', 'user'], true)) {
            throw new \InvalidArgumentException("Rol desconocido: {$roleType}.");
        }

        if (! $grantor->isSuperAdmin($company->id)) {
            throw new PrivilegeEscalationException('Solo el Superusuario cambia el rol de las personas.');
        }

        if ($target->isSuperAdmin($company->id)) {
            throw new PrivilegeEscalationException('El Superusuario es el dueño de la licencia: su rol no se cambia.');
        }

        $current = $target->roleTypeFor($company->id);

        if ($current === null || ! $company->users()->whereKey($target->id)->exists()) {
            throw new PrivilegeEscalationException('Esa persona no pertenece a esta compañía.');
        }

        if ($current === $roleType) {
            return;
        }

        $this->assertSeatAvailable($company, $roleType, $target);

        DB::transaction(function () use ($grantor, $target, $company, $roleType, $current) {
            UserRole::where('user_id', $target->id)
                ->where('company_id', $company->id)
                ->update(['role_id' => $this->globalRole($roleType)->id]);

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $grantor->id,
                'action' => 'user_role_changed',
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'old_values' => ['role_type' => $current],
                'new_values' => ['role_type' => $roleType],
                'created_at' => now(),
            ]);
        });
    }

    /** El rol global de ese tipo ('admin' o 'user'), creado la primera vez. */
    public function globalRole(string $roleType): Role
    {
        return Role::firstOrCreate(
            ['company_id' => null, 'type' => $roleType],
            [
                'name' => $roleType === 'admin' ? 'Administrador' : 'Usuario',
                'can_grant_permissions' => $roleType === 'admin',
            ]
        );
    }

    // ── Permisos por pantalla del menú (ScreenCatalog) ────────────────────

    /**
     * El nivel que $grantor puede dar en cada pantalla: el que tiene él
     * mismo (todo, si es el Superusuario).
     *
     * @return array<string, string> clave de pantalla => nivel
     */
    public function grantableScreenLevels(User $grantor, int $companyId): array
    {
        return app(ScreenAccessService::class)->levelsFor($grantor, $companyId);
    }

    /**
     * Los niveles pedidos para cada pantalla, completos (lo que falta es
     * «sin acceso») y válidos: pantallas que existen, niveles que existen,
     * y nada por encima de lo que admite la pantalla ni de lo que tiene
     * $grantor. $current: lo que ya tiene la persona; un nivel que no cambia
     * no se revisa, así un Administrador puede guardar los permisos de alguien
     * aunque tenga en alguna pantalla más de lo que él podría dar.
     *
     * @param  array<string, string>  $requested
     * @param  array<string, string>  $current
     * @return array<string, string>
     *
     * @throws PrivilegeEscalationException
     */
    public function validateScreenLevels(User $grantor, int $companyId, array $requested, array $current = []): array
    {
        $unknown = array_diff(array_keys($requested), ScreenCatalog::keys());

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Pantalla desconocida: '.implode(', ', $unknown).'.');
        }

        $grantable = $this->grantableScreenLevels($grantor, $companyId);
        $levels = [];

        foreach (ScreenCatalog::screens() as $key => $screen) {
            $level = $requested[$key] ?? 'none';

            if (! isset(self::ACCESS_LEVELS[$level])) {
                throw new \InvalidArgumentException("Nivel de acceso desconocido: {$level}.");
            }

            if (($current[$key] ?? 'none') !== $level) {
                if (self::ACCESS_LEVELS[$level] > self::ACCESS_LEVELS[ScreenCatalog::maxLevel($key)]) {
                    throw new PrivilegeEscalationException("«{$screen['label']}» es de solo consulta: no se puede dar escritura.");
                }

                if (self::ACCESS_LEVELS[$level] > self::ACCESS_LEVELS[$grantable[$key]]) {
                    AuditLog::create([
                        'company_id' => $companyId,
                        'user_id' => $grantor->id,
                        'action' => 'permission_escalation_attempt',
                        'auditable_type' => User::class,
                        'auditable_id' => $grantor->id,
                        'new_values' => ['screen' => $key, 'requested_access_level' => $level],
                        'created_at' => now(),
                    ]);

                    throw new PrivilegeEscalationException("No podés dar más acceso a «{$screen['label']}» del que tenés vos.");
                }
            }

            $levels[$key] = $level;
        }

        return $levels;
    }

    /**
     * Cambia los permisos de $target por pantalla. Reemplaza los que tenía
     * por módulo (el esquema anterior): desde acá, quedan por pantalla.
     *
     * @param  array<string, string>  $requested  clave de pantalla => nivel
     *
     * @throws PrivilegeEscalationException
     */
    public function updateScreenPermissions(User $grantor, User $target, int $companyId, array $requested): void
    {
        if (! $this->canManage($grantor, $target, $companyId)) {
            throw new PrivilegeEscalationException('No podés modificar los permisos de este usuario.');
        }

        $current = app(ScreenAccessService::class)->levelsFor($target, $companyId);
        $levels = $this->validateScreenLevels($grantor, $companyId, $requested, $current);

        DB::transaction(function () use ($target, $companyId, $levels, $current, $grantor) {
            $this->writeScreenLevels($companyId, $target, $levels);

            AuditLog::create([
                'company_id' => $companyId,
                'user_id' => $grantor->id,
                'action' => 'permission_change',
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'old_values' => array_filter($current, fn ($level) => $level !== 'none'),
                'new_values' => array_filter($levels, fn ($level) => $level !== 'none'),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Deja a $user con exactamente estos niveles por pantalla en la
     * compañía: borra sus filas por pantalla y por módulo, y crea una por
     * cada pantalla con acceso. Sin validar: quien llama ya lo hizo.
     *
     * @param  array<string, string>  $levels
     */
    public function writeScreenLevels(int $companyId, User $user, array $levels): void
    {
        ScreenPermission::where('company_id', $companyId)->where('user_id', $user->id)->delete();

        ModulePermission::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('subject_type', 'user')
            ->where('subject_id', $user->id)
            ->delete();

        $now = now();
        $rows = [];

        foreach ($levels as $screen => $level) {
            if ($level !== 'none') {
                $rows[] = ['company_id' => $companyId, 'user_id' => $user->id, 'screen' => $screen, 'access_level' => $level, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            ScreenPermission::insert($rows);
        }
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
