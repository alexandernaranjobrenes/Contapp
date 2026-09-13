<?php

namespace App\Http\Middleware;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la compañía activa de la sesión y la deja seteada en CurrentCompany
 * para que el CompanyScope de todos los modelos filtre correctamente durante
 * el request. Si el usuario no pertenece a ninguna compañía (recién creado,
 * pendiente de asignación, o es platform admin sin compañía propia),
 * CurrentCompany queda sin setear — el scope falla cerrado (ver
 * docs/decisiones.md 2026-08-05), que es el comportamiento correcto.
 *
 * También resuelve el estado de la licencia de la compañía activa (si nació
 * de una) y el estado de la MEMBRESÍA del usuario a esa compañía
 * (company_user.status — ver PermissionGrantService/UserLifecycleService):
 * una licencia SUSPENDIDA/REVOCADA, o una membresía SUSPENDIDA/DEACTIVATED,
 * descartan esa compañía como candidata. Como la misma persona puede
 * pertenecer a compañías de VARIAS licencias distintas (identidad no
 * amarrada a una sola cuenta, ver PermissionGrantService::inviteUser()), el
 * criterio es: mientras le quede AL MENOS UNA compañía utilizable, nunca se
 * cierra la sesión — solo se reasigna la compañía activa a esa alternativa.
 * El logout total queda reservado exclusivamente para cuando no le queda
 * ninguna compañía utilizable (todas sus membresías suspendidas/desactivadas,
 * o todas las licencias de sus compañías bloqueadas).
 *
 * Una licencia VENCIDA pero activa (ni suspendida ni revocada) SÍ es
 * utilizable, pero entra en "modo de gracia" (CurrentCompany::setGraceMode)
 * — la sesión sigue, y es EnforceLicenseGracePeriod quien bloquea únicamente
 * las acciones que escriben datos nuevos (CLAUDE.md secc. 13: nunca un corte
 * abrupto solo por vencimiento). Una compañía sin license_id (creada
 * directo, ej. por seeder/tinker) no tiene ningún candado de licencia.
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        // Guard explícito ('web'), no el default implícito: en una request
        // autenticada por el guard 'propietario' (rutas /backoffice/*), el
        // middleware Authenticate ya llamó Auth::shouldUse('propietario')
        // ANTES de que este middleware corra (auth:propietario está en la
        // lista de prioridad de Laravel, por delante de este middleware
        // aunque esté "append"eado al grupo 'web') — sin este guard
        // explícito, $request->user() devolvería el Propietario acá, que no
        // tiene companies() ni tiene nada que ver con esta resolución.
        $user = $request->user('web');

        if (! $user) {
            return $next($request);
        }

        // Solo membresías activas cuentan como elegibles: una suspendida o
        // desactivada nunca se ofrece como compañía activa, ni siquiera como
        // fallback silencioso.
        $eligible = $user->companies()->wherePivot('status', 'active')->with('license')->get();

        // (int) explícito: CompanySwitchController guarda en sesión el valor
        // tal cual llega del <select> del frontend (event.target.value en JS
        // SIEMPRE es string, y la regla de validación 'integer' de Laravel
        // NO castea, solo valida el formato) — sin este cast, $c->id (int,
        // vía Eloquent/PDO) nunca es === a "355" (string), la comparación de
        // abajo fallaba SIEMPRE, y el cambio de compañía nunca "pegaba": cada
        // request volvía a caer en el fallback de default_company_id.
        $preferredId = (int) $request->session()->get('current_company_id');
        $usable = $eligible->first(fn ($c) => $c->id === $preferredId && (! $c->license_id || ! $c->license->isBlocked()))
            ?? $eligible->first(fn ($c) => $c->id === $user->default_company_id && (! $c->license_id || ! $c->license->isBlocked()))
            ?? $eligible->first(fn ($c) => ! $c->license_id || ! $c->license->isBlocked());

        if ($usable) {
            // Siempre normaliza a int, no solo cuando cambia: si el
            // preferido ya coincidía, la sesión seguía teniendo el string
            // crudo que mandó el <select> — inofensivo para esta comparación
            // (ya cast arriba), pero HandleInertiaRequests comparte este
            // mismo valor de sesión tal cual al frontend, y ahí sí conviene
            // que sea siempre un int consistente.
            $request->session()->put('current_company_id', $usable->id);

            app(CurrentCompany::class)->setGraceMode((bool) $usable->license?->isExpiredButActive());
            app(CurrentCompany::class)->set($usable->id);

            return $next($request);
        }

        // Nada usable: o no pertenece a ninguna compañía todavía (usuario
        // recién creado, comportamiento preservado: CurrentCompany queda sin
        // setear, el scope falla cerrado), o le quedan compañías pero
        // ninguna con membresía activa y licencia no bloqueada — recién acá
        // corresponde cerrar la sesión.
        if ($user->companies()->exists()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $eligible->isEmpty()
                ? 'Tu acceso a CONTAPP fue suspendido. Contactá a quien administra tu cuenta.'
                : 'La licencia de tu compañía está suspendida o fue revocada. Contactá a CONTAPP.';

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
