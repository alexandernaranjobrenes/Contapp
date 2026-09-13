<?php

namespace App\Http\Middleware;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Guard explícito ('web'), no el default implícito: este middleware
        // corre en el grupo global 'web', ANTES que el middleware de ruta
        // auth:propietario — Auth::shouldUse() todavía no cambió el guard
        // default en este punto del pipeline, así que depender de él acá
        // sería frágil. Mismo criterio para leer el guard 'propietario'.
        $user = $request->user('web');
        $propietario = $request->user('propietario');
        $currentCompanyId = app(CurrentCompany::class)->id();

        // CLAUDE.md secc. 17: pie de página con referencia de licencia
        // (enmascarada) + vigencia, visible para cualquier usuario de
        // compañía — quedó señalado como pendiente desde la entrega del
        // modo de gracia (2026-08-27) hasta esta.
        $license = $currentCompanyId ? Company::find($currentCompanyId)?->license?->loadMissing('category') : null;

        // Resuelto UNA sola vez y reutilizado abajo (is_super_admin,
        // can_manage_users, role_type): este método corre en TODA request
        // Inertia, así que evitar llamar User::isSuperAdmin() más de una vez
        // acá importa para el rendimiento general de la app, no solo el de
        // esta pantalla.
        $isSuperAdmin = $user ? $user->isSuperAdmin($currentCompanyId) : false;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // Contextual a la compañía activa, no un privilegio
                    // global: la misma persona puede ser Superusuario de una
                    // licencia y simple Usuario invitado en otra (ver
                    // User::isSuperAdmin() y PermissionGrantService::inviteUser()).
                    'is_super_admin' => $isSuperAdmin,
                    'can_manage_users' => $currentCompanyId
                        ? ($isSuperAdmin || $user->canGrantPermissionsFor($currentCompanyId))
                        : false,
                    // Rol real (no solo 2 booleanos) para mostrar en el
                    // frontend, ver AppLayout.vue.
                    'role_type' => $currentCompanyId
                        ? ($isSuperAdmin ? 'super_admin' : ($user->roleTypeFor($currentCompanyId) ?? 'user'))
                        : null,
                ] : null,
            ],
            'propietario' => $propietario ? [
                'id' => $propietario->id,
                'name' => $propietario->name,
                'email' => $propietario->email,
            ] : null,
            'companies' => $user
                ? $user->companies()->wherePivot('status', 'active')->get(['companies.id', 'companies.legal_name', 'companies.trade_name'])
                : [],
            'currentCompanyId' => $request->session()->get('current_company_id'),
            'licenseGrace' => app(CurrentCompany::class)->isInGracePeriod(),
            // Para que AppLayout.vue pueda ocultar ítems de nav sin permiso
            // en el módulo correspondiente, en vez de mostrar 18 ítems
            // idénticos sin importar el nivel de acceso real del usuario.
            'moduleAccess' => ($user && $currentCompanyId)
                ? $this->moduleAccessFor($user, $currentCompanyId, $isSuperAdmin)
                : (object) [],
            'license' => $license ? [
                'category' => $license->category?->name,
                'masked_code' => $license->maskedCode(),
                'expires_at' => $license->expires_at->format('Y-m-d'),
                'display_status' => $license->displayStatus(),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'importErrors' => fn () => $request->session()->get('importErrors'),
            ],
        ];
    }

    /**
     * Deliberadamente en 2 queries fijas (catálogo de módulos + permisos del
     * usuario), NUNCA una por módulo: esto corre en TODA request Inertia, a
     * diferencia de PermissionGrantService::effectiveAccessLevel(), pensado
     * para un chequeo puntual (ver EnsureModuleAccess), no para llamarse en
     * loop acá.
     *
     * @return array<string, string> module code => access_level
     */
    private function moduleAccessFor(User $user, int $companyId, bool $isSuperAdmin): array
    {
        $modules = Module::query()->pluck('code', 'id');

        if ($isSuperAdmin) {
            return $modules->mapWithKeys(fn (string $code) => [$code => 'read_write'])->all();
        }

        $granted = ModulePermission::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('subject_type', 'user')
            ->where('subject_id', $user->id)
            ->pluck('access_level', 'module_id');

        return $modules->mapWithKeys(fn (string $code, int $id) => [$code => $granted[$id] ?? 'none'])->all();
    }
}
