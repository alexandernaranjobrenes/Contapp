<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use Illuminate\Support\Facades\DB;

/**
 * Las ventas netas de cada uno de los últimos 12 meses, desde los
 * comprobantes de Facturación emitidos: la venta neta (sin impuesto) en
 * moneda local, con su tipo de cambio. Una nota de crédito resta.
 */
class SalesByMonthWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'sales_by_month';
    }

    public function title(): string
    {
        return 'Ventas por mes';
    }

    public function description(): string
    {
        return 'La venta neta facturada en cada uno de los últimos 12 meses, sin impuestos y descontando las notas de crédito.';
    }

    public function section(): string
    {
        return 'billing';
    }

    public function kind(): string
    {
        return 'columns';
    }

    public function screens(): array
    {
        return ['billing.sales_documents'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('sales-documents.index');
    }

    public function data(DashboardContext $context): array
    {
        $company = $context->company;
        $first = $context->today->copy()->startOfMonth()->subMonths(11);
        $month = 'SUBSTR(document_date, 1, 7)';

        $totals = DB::table('sales_documents')
            ->where('company_id', $company->id)
            ->where('status', 'posted')
            ->whereDate('document_date', '>=', $first->toDateString())
            ->whereDate('document_date', '<=', $context->today->toDateString())
            ->groupBy(DB::raw($month))
            ->selectRaw("{$month} as ym, COALESCE(SUM(
                CASE WHEN fiscal_document_type = ? THEN -1 ELSE 1 END
                * total_net_sale
                * CASE WHEN currency_id = ? THEN 1 ELSE exchange_rate END
            ), 0) as total", [SalesDocument::CREDIT_NOTE, $company->local_currency_id])
            ->pluck('total', 'ym');

        $points = [];
        for ($i = 0; $i < 12; $i++) {
            $date = $first->copy()->addMonths($i);
            $labels = DashboardContext::monthLabels($date);
            $points[] = [
                'label' => $labels['label'],
                'title' => ucfirst($labels['title']),
                'values' => ['sales' => DashboardContext::money($totals[$date->format('Y-m')] ?? 0)],
            ];
        }

        $current = $points[11]['values']['sales'];
        $previous = $points[10]['values']['sales'];

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'mode' => 'grouped',
            'series' => [['key' => 'sales', 'label' => 'Ventas netas']],
            'points' => $points,
            'summary' => [
                ['label' => 'Este mes', 'value' => $current],
                ['label' => 'Mes anterior', 'value' => $previous],
            ],
        ];
    }
}
