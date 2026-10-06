<?php

namespace App\Providers;

use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Banking\Services\BccrSoapExchangeRateClient;
use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Services\Hacienda\UnconfiguredHaciendaSigner;
use App\Domains\Billing\Services\Hacienda\UnconfiguredHaciendaTransport;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);

        // A nombre de quién consulta Conti mientras responde un mensaje
        // (ContiChatController). Por petición: nunca se arrastra a otra.
        $this->app->scoped(ContiContext::class);

        $this->app->bind(BccrExchangeRateClient::class, BccrSoapExchangeRateClient::class);

        // Firma y envío a Hacienda: por defecto fallan explícitamente porque
        // exigen el certificado del contribuyente y credenciales del ATV. Al
        // configurarlos se reemplazan estos dos bind por la implementación
        // real, sin tocar nada más del módulo de facturación.
        $this->app->bind(HaciendaSigner::class, UnconfiguredHaciendaSigner::class);
        $this->app->bind(HaciendaTransport::class, UnconfiguredHaciendaTransport::class);
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
    }
}
