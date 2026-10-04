<?php

namespace App\Http\Middleware;

use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\ScreenCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforcement real de los permisos — uso: ->middleware('module-access:reports,read').
 *
 * Dos pasos:
 *  1. El módulo: el permiso del módulo o el de cualquiera de sus pantallas.
 *  2. La pantalla del menú a la que pertenece la ruta (ScreenCatalog), con
 *     el mismo nivel que pide el grupo: 'read' para consultar, 'read_write'
 *     para crear, modificar o eliminar. Una ruta compartida entre pantallas
 *     (el libro mayor de una cuenta) se queda con el primer paso.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $moduleCode, string $minLevel): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->id();

        abort_unless(
            $user && $companyId && app(PermissionGrantService::class)->hasAtLeast($user, $companyId, $moduleCode, $minLevel),
            403
        );

        $screen = ScreenCatalog::screenForRoute($request->route()?->getName());

        if ($screen !== null && ! $user->isSuperAdmin()) {
            abort_unless(app(ScreenAccessService::class)->allows($user, $companyId, $screen, $minLevel), 403);
        }

        return $next($request);
    }
}
