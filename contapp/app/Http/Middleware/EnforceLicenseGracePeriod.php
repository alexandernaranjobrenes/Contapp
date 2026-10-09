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
 *
 * También quedan exentas las que no tocan la compañía vencida sino a la
 * persona: activar su propia licencia (license-redemption.*), elegir su
 * contraseña con el enlace del correo (password.update) y editar sus datos
 * en «Mi cuenta» (profile.*). Que la licencia de otro esté vencida no es
 * motivo para impedirle contratar la suya ni cambiar su contraseña.
 */
class EnforceLicenseGracePeriod
{
    private const EXEMPT_ROUTES = [
        'logout',
        'company-switch',
        'license-redemption.store',
        'license-redemption.password-link',
        'password.update',
        'profile.update',
        'profile.password',
        'profile.email.request',
        'profile.photo.update',
        'profile.photo.destroy',
        // Aceptar una licencia asignada desde el backoffice: es de la
        // persona, no de la compañía en la que esté parada.
        'license-invitation.accept',
        // Los datos de una compañía desde Mi cuenta: la compañía editada
        // puede no ser la activa, así que su licencia la revisa
        // CompanyDetailsController, no este middleware.
        'profile.companies.update',
        // Aceptar una invitación a otra compañía: es de la persona.
        'company-invitation.accept',
        // El canal de comentarios sobre CONTAPP: no es de la compañía, y
        // quien tiene la licencia vencida también tiene algo que decir.
        'feedback.store',
        'feedback.replies.store',
        'feedback.vote',
        'feedback.destroy',
        // Hablar con Conti no escribe nada: consulta. Lo que Conti prepare
        // para guardar se frena aparte (ContiActionService), y confirmarlo
        // (conti.actions.confirm) sigue bloqueado acá. Descartarlo, no.
        'conti.messages.store',
        'conti.actions.discard',
        // El modelo con que Conti le responde es de la persona, no de la
        // compañía.
        'conti.settings.model',
        // Las fotos del QR: la licencia que cuenta es la de la compañía del
        // escaneo, y esa la revisa ContiScanService (no la del teléfono).
        'conti.phone.upload',
        // El código de barras desde el teléfono: no escribe nada, lo deja
        // para la computadora (BarcodeScanService). Si el teléfono tiene una
        // sesión abierta, su compañía no es la del escaneo.
        'barcode-phone.store',
    ];

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
