<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Models\UserRole;
use App\Domains\Core\Scopes\CompanyScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// is_super_admin queda deliberadamente fuera de fillable: es un privilegio
// que solo debe cambiarse por vía administrativa explícita, nunca por
// asignación masiva desde un formulario.
#[Fillable(['name', 'email', 'password', 'default_company_id', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['is_default', 'status'])
            ->withTimestamps();
    }

    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Superusuario es dueño de UNA licencia (License.superuser_id), no un
     * privilegio global de por vida: la misma persona puede ser Superusuario
     * de la Licencia A y, a la vez, un simple Usuario invitado en una
     * compañía de la Licencia B (ver PermissionGrantService::inviteUser()).
     * Por eso esto se resuelve CONTRA la compañía activa, no contra la
     * columna users.is_super_admin sola.
     *
     * $companyId nulo (default): usa la compañía activa de CurrentCompany —
     * cubre los ~19 call-sites HTTP existentes sin que tengan que cambiar,
     * porque SetCurrentCompany ya la resolvió antes de que corran.
     *
     * Sin compañía resuelta, o compañía sin license_id (seed/tinker/tests
     * que crean Company::factory() sin licencia): cae al criterio legado
     * de la columna cruda — es lo que mantiene vivos los tests existentes
     * que arman su propio User::factory(['is_super_admin' => true]) sin
     * pasar por LicenseActivationService.
     */
    public function isSuperAdmin(?int $companyId = null): bool
    {
        $companyId ??= app(\App\Domains\Core\Support\CurrentCompany::class)->id();

        if ($companyId === null) {
            return (bool) $this->is_super_admin;
        }

        $license = Company::find($companyId)?->license;

        if ($license === null) {
            return (bool) $this->is_super_admin;
        }

        return $license->superuser_id === $this->id;
    }

    /**
     * 'admin'|'user'|null — el tipo de rol delegado que este usuario tiene
     * DENTRO de esta compañía (ver PermissionGrantService). Un Superusuario
     * no tiene fila en user_roles (es is_super_admin, un nivel por encima).
     */
    public function roleTypeFor(int $companyId): ?string
    {
        return $this->userRoles()
            ->where('company_id', $companyId)
            ->with('role:id,type')
            ->get()
            ->pluck('role.type')
            ->first();
    }

    public function canGrantPermissionsFor(int $companyId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->userRoles()
            ->where('company_id', $companyId)
            ->whereHas('role', fn ($q) => $q->where('can_grant_permissions', true))
            ->exists();
    }

    /**
     * 'none'|'read'|'read_write' — su propio permiso otorgado para ese
     * módulo en esa compañía (un Superusuario no necesita fila acá: ver
     * PermissionGrantService::effectiveAccessLevel, que lo trata aparte).
     */
    public function modulePermissionFor(int $companyId, int $moduleId): string
    {
        // withoutGlobalScope: este método (y PermissionGrantService, que
        // depende de él) debe funcionar sin CurrentCompany ambiental — igual
        // que el resto de los servicios de este proyecto que no dependen del
        // contexto HTTP (ver LedgerService/TrialBalanceService). Sin el
        // bypass, CompanyScope falla cerrado (ver docs/decisiones.md
        // 2026-08-05) y esto devuelve 'none' SIEMPRE, aunque el permiso exista.
        $permission = ModulePermission::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('module_id', $moduleId)
            ->where('subject_type', 'user')
            ->where('subject_id', $this->id)
            ->first();

        return $permission?->access_level ?? 'none';
    }
}
