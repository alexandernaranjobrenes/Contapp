<?php

namespace App\Providers;

use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Banking\Services\BccrSoapExchangeRateClient;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);

        $this->app->bind(BccrExchangeRateClient::class, BccrSoapExchangeRateClient::class);
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
    }
}
