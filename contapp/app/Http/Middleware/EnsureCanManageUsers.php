<?php

namespace App\Http\Middleware;

use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatekeeper de /users/*: solo el Superusuario de la compañía o un
 * Administrador con can_grant_permissions llega a gestionar otros usuarios
 * (CLAUDE.md secc. 12) — un Usuario común nunca, no delega nada.
 */
class EnsureCanManageUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->id();

        abort_unless(
            $user && $companyId && ($user->isSuperAdmin() || $user->canGrantPermissionsFor($companyId)),
            403
        );

        return $next($request);
    }
}
