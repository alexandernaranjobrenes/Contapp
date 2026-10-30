<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Dashboard\MonthlyResults;

/** El resultado del mes en curso (ingresos − gastos), contra el mes anterior. */
class MonthResultWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'month_result';
    }

    public function title(): string
    {
        return 'Resultado del mes';
    }

    public function description(): string
    {
        return 'Ingresos menos gastos del mes en curso, comparado con el mes anterior y con la tendencia de los últimos 12 meses.';
    }

    public function section(): string
    {
        return 'accounting';
    }

    public function kind(): string
    {
        return 'stat';
    }

    public function size(): string
    {
        return 'small';
    }

    public function screens(): array
    {
        return ['reports.income_statement'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('reports.income-statement.index');
    }

    public function data(DashboardContext $context): array
    {
        $months = MonthlyResults::for($context);
        $current = $months[count($months) - 1];
        $previous = $months[count($months) - 2];

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'value' => $current['result'],
            'period' => ucfirst($current['title']),
            'details' => [
                ['label' => 'Ingresos', 'value' => $current['income']],
                ['label' => 'Gastos', 'value' => $current['expenses']],
            ],
            'delta' => [
                'value' => DashboardContext::money($current['result'] - $previous['result']),
                'versus' => "que en {$previous['title']}",
                'style' => 'comparison',
                'good' => 'up',
            ],
            'trend' => array_column($months, 'result'),
        ];
    }
}
