<?php

namespace App\Providers;

use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Banking\Services\BccrSddeExchangeRateClient;
use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Services\Hacienda\HaciendaApiClient;
use App\Domains\Billing\Services\Hacienda\XadesEpesSigner;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Services\ActivityRecorder;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);

        // La Bitácora (CLAUDE.md secc. 35): junta lo de cada pedido y lo
        // escribe al terminar.
        $this->app->singleton(ActivityRecorder::class);

        // A nombre de quién consulta Conti mientras responde un mensaje
        // (ContiChatController). Por petición: nunca se arrastra a otra.
        $this->app->scoped(ContiContext::class);

        $this->app->bind(BccrExchangeRateClient::class, BccrSddeExchangeRateClient::class);

        // Firma y envío a Hacienda (CLAUDE.md secc. 38). La llave y las
        // credenciales no son de la instalación sino de cada compañía: las
        // carga su Superusuario y viajan como argumento, no en el bind.
        $this->app->bind(HaciendaSigner::class, XadesEpesSigner::class);
        $this->app->bind(HaciendaTransport::class, HaciendaApiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alias cortos para los subject_type de module_permissions/document_type_permissions.
        // No es "enforceMorphMap": otros polimórficos (ej. audit_logs.auditable_type)
        // siguen resolviendo por FQCN normalmente.
        Relation::morphMap([
            'user' => User::class,
            'role' => Role::class,
        ]);

        // El canal de comentarios (FeedbackController), por cuenta. Con
        // nombre y no `throttle:10,1`: esos comparten un solo contador por
        // cuenta entre todas las rutas que los usan, y treinta votos dejarían
        // a alguien sin poder publicar.
        RateLimiter::for('feedback-write', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('feedback-vote', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip())));

        // Conti (CLAUDE.md secc. 32): los mensajes de una persona. El consumo
        // real lo limitan los créditos de su licencia (ContiUsageService).
        RateLimiter::for('conti-chat', fn (Request $request) => Limit::perMinute(12)->by((string) ($request->user()?->id ?? $request->ip())));
        // Los formularios del chat no llaman al modelo: enviarlos (con algún
        // error de validación de por medio) y buscar mientras se escribe.
        RateLimiter::for('conti-forms', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('conti-lookup', fn (Request $request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip())));

        // Las solicitudes de licencia del sitio público (CLAUDE.md secc. 42):
        // sin sesión, por IP. Una persona manda una o dos; un robot, cientos.
        RateLimiter::for('license-requests', fn (Request $request) => [
            Limit::perHour(5)->by('hour:'.$request->ip()),
            Limit::perDay(20)->by('day:'.$request->ip()),
        ]);

        // La Bitácora de la compañía (ActivityRecorder, CLAUDE.md secc. 35):
        // todo lo que se crea, cambia o borra, y quién entra y sale.
        Event::listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], function (string $event, array $payload) {
            app(ActivityRecorder::class)->record($payload[0], Str::between($event, 'eloquent.', ':'));
        });
        // Solo las sesiones de la compañía: las del backoffice (guard
        // «propietario») tienen su propia bitácora.
        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web' && $event->user instanceof User) {
                app(ActivityRecorder::class)->session('login', $event->user);
            }
        });
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->guard === 'web' && $event->user instanceof User) {
                app(ActivityRecorder::class)->session('logout', $event->user);
            }
        });
        $this->app->terminating(fn () => app(ActivityRecorder::class)->flush());
    }
}
