<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspender/reactivar/desactivar un Administrador o Usuario — SIEMPRE por
 * licencia, nunca de forma global (ver migración
 * add_status_to_company_user_table): la acción se aplica a todas las
 * compañías de la licencia de $actingCompanyId a las que el target
 * pertenezca, dejando intacta cualquier membresía suya en OTRAS licencias
 * (CLAUDE.md secc. 12/14 ampliado — identidad no amarrada a una sola cuenta).
 *
 * Autorización: misma regla de jerarquía que PermissionGrantService::canManage()
 * — el Superusuario gestiona a cualquiera de su licencia, un Administrador
 * (con can_grant_permissions) gestiona a los Usuarios que él mismo
 * administra, igual que ya puede restringirles funciones. Nadie gestiona a
 * otro Administrador ni al Superusuario salvo el propio Superusuario.
 */
class UserLifecycleService
{
    public function suspend(User $grantor, User $target, int $actingCompanyId): void
    {
        $this->applyStatus($grantor, $target, $actingCompanyId, 'suspended', 'user_suspended');
    }

    /**
     * Reversible desde 'suspended'. Reactivar desde 'deactivated' también es
     * técnicamente posible con este mismo método (la desactivación no borra
     * nada), pero el producto la expone como "permanente": no hay botón de
     * reactivar en la UI para un usuario deactivated (ver Users/Index.vue).
     */
    public function reactivate(User $grantor, User $target, int $actingCompanyId): void
    {
        $this->applyStatus($grantor, $target, $actingCompanyId, 'active', 'user_reactivated');
    }

    /**
     * "Eliminar" un usuario, en los hechos: nunca borra la fila `users` ni
     * nada de su historial (asientos, audit_logs con su nombre siguen
     * intactos) — solo le cierra el acceso a esta licencia de forma
     * indefinida.
     */
    public function deactivate(User $grantor, User $target, int $actingCompanyId): void
    {
        $this->applyStatus($grantor, $target, $actingCompanyId, 'deactivated', 'user_deactivated');
    }

    private function applyStatus(User $grantor, User $target, int $actingCompanyId, string $status, string $action): void
    {
        if (! app(PermissionGrantService::class)->canManage($grantor, $target, $actingCompanyId)) {
            throw new PrivilegeEscalationException('No podés gestionar el estado de este usuario.');
        }

        $company = Company::findOrFail($actingCompanyId);
        $companyIds = $company->license_id
            ? $company->license->companies()->pluck('id')
            : collect([$company->id]);

        DB::table('company_user')
            ->where('user_id', $target->id)
            ->whereIn('company_id', $companyIds)
            ->update(['status' => $status, 'updated_at' => now()]);

        AuditLog::create([
            'company_id' => $actingCompanyId,
            'user_id' => $grantor->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'new_values' => ['status' => $status, 'scope_company_ids' => $companyIds->toArray()],
            'created_at' => now(),
        ]);
    }
}
