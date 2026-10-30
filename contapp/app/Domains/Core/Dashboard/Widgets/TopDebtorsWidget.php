<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\BusinessPartners\DataTransferObjects\AgingRow;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;

/** Los cinco clientes que más deben hoy, en moneda local (de la antigüedad de saldos). */
class TopDebtorsWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'top_debtors';
    }

    public function title(): string
    {
        return 'Clientes que más deben';
    }

    public function description(): string
    {
        return 'Los cinco clientes con más saldo pendiente hoy, en moneda local.';
    }

    public function section(): string
    {
        return 'business_partners';
    }

    public function kind(): string
    {
        return 'bars';
    }

    public function screens(): array
    {
        return ['reports.aging'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('reports.aging.index');
    }

    public function data(DashboardContext $context): array
    {
        $group = AgingWidget::localGroup($context, AgingWidget::aging($context, 'client')->groups);

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'rows' => collect($group?->rows ?? [])
                ->sortByDesc(fn (AgingRow $row) => (float) $row->total)
                ->take(5)
                ->map(fn (AgingRow $row) => [
                    'label' => $row->partnerName,
                    'sublabel' => $row->partnerCode,
                    'value' => DashboardContext::money($row->total),
                ])->values()->all(),
            'empty' => 'Ningún cliente tiene saldo pendiente.',
        ];
    }
}
