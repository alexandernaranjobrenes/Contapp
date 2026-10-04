<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Models\ScreenPermission;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\ScreenCatalog;
use App\Models\User;

/**
 * El acceso de una cuenta a cada pantalla del menú, en una compañía.
 *
 * Hay dos fuentes, y vale la más alta:
 *
 *  - screen_permissions: el nivel en una pantalla (el editor de permisos
 *    actual).
 *  - module_permissions: el esquema anterior, un nivel por módulo, que vale
 *    para todas las pantallas de ese módulo (ScreenCatalog: «module»). Así
 *    nadie perdió acceso al pasar a permisos por pantalla. Al guardar los
 *    permisos de alguien con el editor actual, los suyos por módulo se
 *    reemplazan por filas por pantalla (PermissionGrantService).
 *
 * El Superusuario tiene todo, sin filas. Una pantalla de solo consulta (un
 * reporte) nunca pasa de Lectura.
 */
class ScreenAccessService
{
    public const LEVELS = ['none' => 0, 'read' => 1, 'read_write' => 2];

    /**
     * El nivel de cada pantalla.
     *
     * @return array<string, string> clave de pantalla => 'none'|'read'|'read_write'
     */
    public function levelsFor(User $user, int $companyId): array
    {
        if ($user->isSuperAdmin($companyId)) {
            return $this->all(fn (string $key) => ScreenCatalog::maxLevel($key));
        }

        $byModule = ModulePermission::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('subject_type', 'user')
            ->where('subject_id', $user->id)
            ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
            ->pluck('module_permissions.access_level', 'modules.code');

        $byScreen = ScreenPermission::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->pluck('access_level', 'screen');

        return $this->all(function (string $key, array $screen) use ($byModule, $byScreen) {
            $level = self::max($byScreen[$key] ?? 'none', $byModule[$screen['module']] ?? 'none');

            return self::min($level, ScreenCatalog::maxLevel($key));
        });
    }

    public function levelFor(User $user, int $companyId, string $screenKey): string
    {
        return $this->levelsFor($user, $companyId)[$screenKey] ?? 'none';
    }

    public function allows(User $user, int $companyId, string $screenKey, string $minLevel): bool
    {
        return self::LEVELS[$this->levelFor($user, $companyId, $screenKey)] >= self::LEVELS[$minLevel];
    }

    /**
     * El nivel de un módulo: el más alto entre su permiso por módulo y el de
     * sus pantallas. Es lo que pide el middleware `module-access` de un grupo
     * de rutas; la pantalla puntual la revisa después (EnsureModuleAccess).
     */
    public function moduleLevel(User $user, int $companyId, string $moduleCode): string
    {
        if ($user->isSuperAdmin($companyId)) {
            return 'read_write';
        }

        $level = 'none';

        foreach ($this->levelsFor($user, $companyId) as $key => $screenLevel) {
            if (ScreenCatalog::find($key)['module'] === $moduleCode) {
                $level = self::max($level, $screenLevel);
            }
        }

        // Un módulo sin pantallas en el catálogo conserva su permiso tal cual.
        $module = Module::where('code', $moduleCode)->first();

        if ($module !== null) {
            $level = self::max($level, $user->modulePermissionFor($companyId, $module->id));
        }

        return $level;
    }

    public static function max(string $a, string $b): string
    {
        return self::LEVELS[$a] >= self::LEVELS[$b] ? $a : $b;
    }

    public static function min(string $a, string $b): string
    {
        return self::LEVELS[$a] <= self::LEVELS[$b] ? $a : $b;
    }

    /** @return array<string, string> */
    private function all(callable $levelOf): array
    {
        $levels = [];

        foreach (ScreenCatalog::screens() as $key => $screen) {
            $levels[$key] = $levelOf($key, $screen);
        }

        return $levels;
    }
}
