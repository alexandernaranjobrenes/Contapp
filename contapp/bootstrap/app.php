<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $middleware->alias([
            'can-manage-users' => \App\Http\Middleware\EnsureCanManageUsers::class,
            'module-access' => \App\Http\Middleware\EnsureModuleAccess::class,
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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
