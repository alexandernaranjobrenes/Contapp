<?php

namespace App\Http\Middleware;

use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatekeeper de la configuración de la propia compañía (hoy, su apariencia):
 * el Superusuario o un Administrador de la compañía activa. Un Usuario
 * común no, porque cambia lo que ven todos los demás.
 */
class EnsureCanManageCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->id();

        abort_unless(
            $user && $companyId && ($user->isSuperAdmin() || $user->roleTypeFor($companyId) === 'admin'),
            403
        );

        return $next($request);
    }
}
