<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // SetCurrentCompany va antes que HandleInertiaRequests a propósito:
            // Inertia\Middleware::share() corre ANTES de $next($request), así
            // que si compartiera 'licenseGrace' antes de que SetCurrentCompany
            // resuelva el modo de gracia de esta request, siempre vería el
            // valor por defecto (false) sin importar la licencia real.
            \App\Http\Middleware\SetCurrentCompany::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnforceLicenseGracePeriod::class,
        ]);

        // Cada sesión guarda el hash de la contraseña con la que se abrió; si
        // la contraseña cambia (ver NewPasswordController), las sesiones
        // abiertas con la anterior se cierran en su siguiente request. Sin
        // esto, recuperar la contraseña no sacaría a quien ya estuviera
        // adentro con la vieja. Va por guard: no mezcla al Propietario con
        // los usuarios de compañía.
        $middleware->authenticateSessions();

        $middleware->alias([
            'can-manage-users' => \App\Http\Middleware\EnsureCanManageUsers::class,
            'can-manage-company' => \App\Http\Middleware\EnsureCanManageCompany::class,
            'module-access' => \App\Http\Middleware\EnsureModuleAccess::class,
            'screen-access' => \App\Http\Middleware\EnsureScreenAccess::class,
        ]);

        // Sin esto, un guest golpeando una ruta auth:propietario cae al
        // login de cliente (equivocado), y un Propietario ya logueado
        // golpeando /backoffice/login (bloqueado por guest:propietario) cae
        // a route('dashboard') — una ruta que nunca puede pasar para él.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('backoffice/*') ? route('backoffice.login') : route('login')
        );
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('backoffice/*') ? route('backoffice.licenses.index') : route('dashboard')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // En JSON: la API, y las descargas que pide resources/js/Utils/
        // downloads.js (cabecera X-Contapp-Download). Así un Exportar que
        // falla dice por qué —el error de validación, el motivo del 403— en
        // vez de llevarse la pantalla a una página de error. También el panel
        // «Comentarios y noticias» de la barra superior (FeedbackController),
        // que habla solo JSON: el error va junto al campo o en un aviso.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->hasHeader('X-Contapp-Download')
                || $request->routeIs('feedback.*', 'news.index', 'conti.messages.*', 'conti.settings.*', 'conti.forms.*', 'conti.scan.*', 'conti.phone.upload', 'items.barcode-scans.*', 'items.next-barcode', 'barcode-phone.store', 'activity-log.more'),
        );

        // Una acción de Inertia (guardar, eliminar, contabilizar…) que
        // termina en error —sin permiso, sesión vencida, no encontrado, error
        // interno— vuelve a la pantalla de la que salió con el motivo como
        // mensaje, que el layout muestra arriba, y el botón que la disparó se
        // libera (CLAUDE.md secc. 27). Sin esto, Inertia dibuja la página de
        // error cruda en una ventana encima de la aplicación.
        //
        // Solo las que escriben. Una visita GET que falla no puede «volver»
        // sin riesgo: la pantalla anterior puede ser ella misma (recargar
        // una página que ya no existe) y se entraría en un bucle. Esas las
        // avisa el navegador (resources/js/Utils/requestErrors.js), y el
        // cambio de compañía tiene su propio manejo.
        //
        // Los mismos textos que resources/js/Utils/notify.js. En desarrollo,
        // un error interno se deja pasar: la página de Laravel con el detalle
        // es lo que necesita quien programa.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request) {
            if (! $request->hasHeader('X-Inertia') || $request->isMethodSafe()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status >= 500 && config('app.debug')) {
                return $response;
            }

            $own = $exception instanceof HttpExceptionInterface ? trim($exception->getMessage()) : '';

            $message = match (true) {
                $status === 403 => $own !== '' && $own !== 'This action is unauthorized.' ? $own : 'No tenés permiso para hacer esto.',
                $status === 404 => 'No encontramos lo que buscabas. Puede que ya no exista.',
                $status === 419 => 'La página estuvo abierta mucho tiempo y la sesión expiró. Volvé a intentarlo.',
                $status === 429 => 'Demasiados intentos seguidos. Esperá un momento y volvé a probar.',
                $status === 503 => 'CONTAPP está en mantenimiento. Probá de nuevo en unos minutos.',
                $status >= 500 => 'Ocurrió un error en el servidor y la acción no se completó. Si se repite, avisale al equipo de CONTAPP.',
                default => null,
            };

            // 303 y no 302: una visita PUT o DELETE que vuelve con 302 se repetiría
            // como PUT o DELETE contra la pantalla anterior.
            return $message === null ? $response : back(303)->with('error', $message);
        });
    })->create();
