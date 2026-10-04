<?php

namespace App\Http\Middleware;

use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El permiso de una pantalla del menú, para las rutas que no están en un
 * grupo `module-access` (que ya revisa la pantalla, ver EnsureModuleAccess).
 * Uso: ->middleware('screen-access:tax.tax_rates,read_write').
 */
class EnsureScreenAccess
{
    public function handle(Request $request, Closure $next, string $screen, string $minLevel): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->id();

        abort_unless(
            $user && $companyId && ($user->isSuperAdmin() || app(ScreenAccessService::class)->allows($user, $companyId, $screen, $minLevel)),
            403
        );

        return $next($request);
    }
}
