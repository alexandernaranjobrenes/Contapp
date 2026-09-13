<?php

namespace App\Domains\Banking\Services;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * Orquesta la carga diaria de tipos de cambio: pregunta al cliente BCCR (o al
 * que esté enlazado en el contenedor, ej. un fake en tests) y guarda el
 * resultado como exchange_rates.source = 'bccr_api'. Idempotente: correr dos
 * veces el mismo día no duplica filas (updateOrCreate por company+currency+fecha+tipo).
 */
class SyncBccrExchangeRatesService
{
    public function __construct(private readonly BccrExchangeRateClient $client)
    {
    }

    public function syncForCompany(Company $company, \DateTimeInterface $date): ?ExchangeRate
    {
        if (! $company->foreign_currency_id) {
            return null;
        }

        $currency = Currency::find($company->foreign_currency_id);

        if (! $currency) {
            return null;
        }

        $rate = $this->client->fetchRate($currency->code, $date);

        if ($rate === null) {
            return null;
        }

        // No se usa updateOrCreate: el cast 'date' de rate_date normaliza el
        // valor guardado a un datetime completo, y una comparación de
        // igualdad exacta contra 'Y-m-d' plano nunca calza con lo almacenado.
        // whereDate() sí compara solo la parte de fecha, sin ese problema.
        $existing = ExchangeRate::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('currency_id', $currency->id)
            ->where('rate_type', 'reference')
            ->whereDate('rate_date', $date->format('Y-m-d'))
            ->first();

        if ($existing) {
            // Una fila bloqueada (is_locked) es una corrección manual
            // deliberada: el sync automático nunca la pisa. La edición
            // manual explícita (ExchangeRateController::store) sí puede,
            // porque ahí el usuario decide a propósito.
            if ($existing->is_locked) {
                return $existing;
            }

            $existing->update([
                'rate' => $rate,
                'source' => 'bccr_api',
            ]);

            return $existing->fresh();
        }

        return ExchangeRate::withoutGlobalScope(CompanyScope::class)->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
            'rate_date' => $date->format('Y-m-d'),
            'rate_type' => 'reference',
            'rate' => $rate,
            'source' => 'bccr_api',
            'is_locked' => false,
        ]);
    }

    /**
     * @return array<int, ExchangeRate|null> keyed by company id
     */
    public function syncForAllCompanies(\DateTimeInterface $date): array
    {
        $results = [];

        Company::withoutGlobalScope(CompanyScope::class)
            ->where('status', 'active')
            ->each(function (Company $company) use ($date, &$results) {
                $results[$company->id] = $this->syncForCompany($company, $date);
            });

        return $results;
    }
}
