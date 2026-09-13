<?php

namespace App\Http\Middleware;

use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforcement real de module_permissions (piloto: módulo "reports", ver
 * docs/decisiones.md) — uso: ->middleware('module-access:reports,read').
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

        return $next($request);
    }
}
