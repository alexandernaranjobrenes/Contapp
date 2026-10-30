<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * El tipo de cambio de referencia de la moneda extranjera de la compañía
 * (el del BCCR, el que usa Hacienda) en los últimos 30 días.
 */
class ExchangeRateWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'exchange_rate';
    }

    public function title(): string
    {
        return 'Tipo de cambio';
    }

    public function description(): string
    {
        return 'El tipo de cambio de referencia del dólar en los últimos 30 días.';
    }

    public function section(): string
    {
        return 'cost_fx';
    }

    public function kind(): string
    {
        return 'line';
    }

    public function size(): string
    {
        return 'small';
    }

    public function screens(): array
    {
        return ['accounting.exchange_rates'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('exchange-rates.index');
    }

    public function data(DashboardContext $context): array
    {
        $company = $context->company;
        $currency = $company->foreignCurrency;

        $rates = $currency
            ? ExchangeRate::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('currency_id', $currency->id)
                ->where('rate_type', 'reference')
                ->whereDate('rate_date', '>=', $context->today->copy()->subDays(30)->toDateString())
                ->whereDate('rate_date', '<=', $context->today->toDateString())
                ->orderBy('rate_date')
                ->get(['rate_date', 'rate'])
            : collect();

        $points = $rates->map(fn (ExchangeRate $r) => [
            'date' => $r->rate_date->toDateString(),
            'label' => DashboardContext::dayLabel($r->rate_date),
            'value' => round((float) $r->rate, 2),
        ])->values()->all();

        $last = $points[count($points) - 1] ?? null;
        $first = $points[0] ?? null;

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'subject' => $currency ? "{$context->currency['code']} por {$currency->code}" : null,
            'value' => $last['value'] ?? null,
            'asOf' => $last['label'] ?? null,
            'delta' => $last && $first && $first !== $last ? [
                'value' => round($last['value'] - $first['value'], 2),
                'versus' => "desde el {$first['label']}",
                // Que suba el dólar no es bueno ni malo: depende de la compañía.
                'good' => null,
            ] : null,
            'points' => $points,
            'empty' => 'No hay tipos de cambio de referencia de los últimos 30 días.',
        ];
    }
}
