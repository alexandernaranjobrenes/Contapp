<?php

namespace App\Http\Middleware;

use App\Domains\Conti\Services\ContiAccessService;
use App\Domains\Conti\Services\ContiModelService;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CompanyTheme;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $company = $currentCompanyId ? Company::find($currentCompanyId) : null;
        $license = $company?->license?->loadMissing('category');

        // Resuelto UNA sola vez y reutilizado abajo (is_super_admin,
        // can_manage_users, role_type): este método corre en TODA request
        // Inertia, así que evitar llamar User::isSuperAdmin() más de una vez
        // acá importa para el rendimiento general de la app, no solo el de
        // esta pantalla.
        $isSuperAdmin = $user ? $user->isSuperAdmin($currentCompanyId) : false;
        $roleType = ($user && $currentCompanyId && ! $isSuperAdmin) ? $user->roleTypeFor($currentCompanyId) : null;

        // El nivel en cada pantalla del menú, una sola vez por visita.
        $screenAccess = match (true) {
            ! $user || ! $currentCompanyId => [],
            $isSuperAdmin => collect(ScreenCatalog::keys())->mapWithKeys(fn (string $key) => [$key => ScreenCatalog::maxLevel($key)])->all(),
            default => app(ScreenAccessService::class)->levelsFor($user, $currentCompanyId),
        };

        // ¿La cuenta ya es dueña de una licencia? No es lo mismo que ser
        // Superusuario de la compañía activa: puede serlo de la suya y estar
        // parada en la de otra persona. De esto depende que el menú ofrezca
        // «Activar una licencia» (una cuenta, una licencia). Si la licencia
        // de la compañía activa es suya, se sabe sin consultar.
        $ownsLicense = $user !== null && (
            $license?->superuser_id === $user->id
            || License::where('superuser_id', $user->id)->exists()
        );

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
                        ? ($isSuperAdmin ? 'super_admin' : ($roleType ?? 'user'))
                        : null,
                    // Administración → Apariencia (CLAUDE.md secc. 31): mismo
                    // criterio que el middleware can-manage-company.
                    'can_manage_company' => $currentCompanyId !== null && ($isSuperAdmin || $roleType === 'admin'),
                    'owns_license' => $ownsLicense,
                    // La foto de perfil (Mi cuenta): reemplaza al ícono de
                    // «Mi cuenta» en el menú. Armar la dirección no consulta
                    // al bucket, y sin foto ni siquiera se resuelve el disco.
                    'photo_url' => $user->photo_path
                        ? app(MediaStorage::class)->versionedUrl($user->photo_path, $user->photo_updated_at)
                        : null,
                ] : null,
            ],
            'propietario' => $propietario ? [
                'id' => $propietario->id,
                'name' => $propietario->name,
                'email' => $propietario->email,
            ] : null,
            // Las compañías del selector de la barra superior, con el estado
            // de su licencia: una suspendida o revocada se muestra marcada y
            // no se puede elegir; una vencida avisa que entra en modo de
            // gracia (CLAUDE.md secc. 30).
            'companies' => $user
                ? $user->companies()->wherePivot('status', 'active')->with('license:id,status,expires_at')
                    ->get(['companies.id', 'companies.legal_name', 'companies.trade_name', 'companies.theme', 'companies.license_id'])
                    ->map(fn (Company $c) => [
                        'id' => $c->id,
                        'legal_name' => $c->legal_name,
                        'trade_name' => $c->trade_name,
                        'theme' => $c->theme,
                        'license_state' => $c->license?->accessState() ?? 'ok',
                    ])
                    ->values()
                : [],
            'currentCompanyId' => $request->session()->get('current_company_id'),
            // El tema visual de la compañía activa (CLAUDE.md secc. 31). Lo
            // aplican app.blade.php en la primera carga y app.js al navegar.
            'companyTheme' => $company ? CompanyTheme::resolve($company->theme)->value : CompanyTheme::default()->value,
            'licenseGrace' => app(CurrentCompany::class)->isInGracePeriod(),
            // El nivel en cada pantalla del menú (ScreenCatalog): AppLayout.vue
            // muestra solo las que la persona puede abrir. moduleAccess es el
            // de cada módulo, el más alto entre sus pantallas.
            'screenAccess' => $screenAccess ?: (object) [],
            'moduleAccess' => ($user && $currentCompanyId)
                ? $this->moduleAccessFor($user, $currentCompanyId, $isSuperAdmin, $screenAccess)
                : (object) [],
            'license' => $license ? [
                'category' => $license->category?->name,
                'masked_code' => $license->maskedCode(),
                'expires_at' => $license->expires_at->format('Y-m-d'),
                'display_status' => $license->displayStatus(),
            ] : null,
            // La fecha de la última noticia del backoffice: el botón de
            // «Comentarios y noticias» de la barra superior lleva un punto
            // mientras haya una que esta persona todavía no vio. Va en caché
            // porque esto corre en toda visita; NewsService la borra al
            // publicar, editar o eliminar una noticia.
            'latestNewsAt' => fn () => $user ? $this->latestNewsAt() : null,
            // Conti, el asistente (CLAUDE.md secc. 32): el chat aparece si la
            // instalación tiene la key de OpenAI, la licencia de la compañía
            // lo tiene activado y el Superusuario no se lo quitó a la
            // persona. Lo que puede ver o hacer lo deciden los permisos de la
            // persona, en el servidor.
            'conti' => [
                'enabled' => $user instanceof User && app(ContiUsageService::class)->enabledFor($company, $user),
                // El modelo con que le responde, para el encabezado del chat.
                // Sale de la configuración, sin preguntarle nada a OpenAI.
                'model' => $user instanceof User
                    ? app(ContiModelService::class)->name(app(ContiModelService::class)->forUser($user, app(ContiAccessService::class)->allowedModels($user, $company)))
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'importErrors' => fn () => $request->session()->get('importErrors'),
            ],
        ];
    }

    /** La fecha de la última noticia, o null si no hay ninguna. */
    private function latestNewsAt(): ?string
    {
        // Sin noticias se guarda '' y no null: un null en caché cuenta como
        // que no está, y se consultaría en cada visita.
        $latest = Cache::remember(NewsPost::LATEST_CACHE_KEY, 600, function () {
            $max = NewsPost::max('created_at');

            return $max ? Carbon::parse($max)->toIso8601String() : '';
        });

        return $latest !== '' ? $latest : null;
    }

    /**
     * Deliberadamente en queries fijas (catálogo de módulos + permisos del
     * usuario), NUNCA una por módulo: esto corre en TODA request Inertia, a
     * diferencia de PermissionGrantService::effectiveAccessLevel(), pensado
     * para un chequeo puntual (ver EnsureModuleAccess), no para llamarse en
     * loop acá. El nivel de cada pantalla ya viene calculado.
     *
     * @param  array<string, string>  $screenAccess
     * @return array<string, string> module code => access_level
     */
    private function moduleAccessFor(User $user, int $companyId, bool $isSuperAdmin, array $screenAccess): array
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

        return $modules->mapWithKeys(function (string $code, int $id) use ($granted, $screenAccess) {
            $level = $granted[$id] ?? 'none';

            foreach ($screenAccess as $key => $screenLevel) {
                if (ScreenCatalog::find($key)['module'] === $code) {
                    $level = ScreenAccessService::max($level, $screenLevel);
                }
            }

            return [$code => $level];
        })->all();
    }
}
