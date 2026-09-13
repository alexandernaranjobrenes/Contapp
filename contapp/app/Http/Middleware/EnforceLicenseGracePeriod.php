<?php

namespace App\Http\Middleware;

use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica el "modo de gracia" que SetCurrentCompany detecta para una licencia
 * vencida (no revocada): deja pasar cualquier método de lectura (GET/HEAD),
 * y bloquea el resto (POST/PUT/PATCH/DELETE) — CLAUDE.md secc. 13:
 * "permitir solo lectura/exportación... bloqueando exclusivamente creación
 * de nuevos registros". Un puñado de rutas de housekeeping de sesión quedan
 * exentas a propósito: cerrar sesión o cambiar de compañía activa no crea
 * ni modifica ningún dato de negocio, y bloquearlas dejaría a alguien en
 * modo de gracia sin forma de salir de la compañía vencida.
 */
class EnforceLicenseGracePeriod
{
    private const EXEMPT_ROUTES = ['logout', 'company-switch'];

    public function handle(Request $request, Closure $next): Response
    {
        $inGrace = app(CurrentCompany::class)->isInGracePeriod();
        $exempt = in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true);

        if ($inGrace && ! $request->isMethodSafe() && ! $exempt) {
            abort(403, 'La licencia de tu compañía está vencida. Podés consultar y exportar información, pero no crear ni modificar registros hasta renovarla.');
        }

        return $next($request);
    }
}
