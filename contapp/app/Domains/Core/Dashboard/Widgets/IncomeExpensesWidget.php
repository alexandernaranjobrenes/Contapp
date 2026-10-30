<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Dashboard\MonthlyResults;

/** Ingresos y gastos de cada uno de los últimos 12 meses, uno al lado del otro. */
class IncomeExpensesWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'income_expenses';
    }

    public function title(): string
    {
        return 'Ingresos y gastos';
    }

    public function description(): string
    {
        return 'Lo que entró y lo que se gastó en cada uno de los últimos 12 meses, según la contabilidad.';
    }

    public function section(): string
    {
        return 'accounting';
    }

    public function kind(): string
    {
        return 'columns';
    }

    public function size(): string
    {
        return 'large';
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

        $income = array_sum(array_column($months, 'income'));
        $expenses = array_sum(array_column($months, 'expenses'));

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'mode' => 'grouped',
            'series' => [
                ['key' => 'income', 'label' => 'Ingresos'],
                ['key' => 'expenses', 'label' => 'Gastos'],
            ],
            'points' => array_map(fn (array $m) => [
                'label' => $m['label'],
                'title' => ucfirst($m['title']),
                'values' => ['income' => $m['income'], 'expenses' => $m['expenses']],
            ], $months),
            'summary' => [
                ['label' => 'Ingresos en 12 meses', 'value' => DashboardContext::money($income)],
                ['label' => 'Gastos en 12 meses', 'value' => DashboardContext::money($expenses)],
                ['label' => 'Resultado', 'value' => DashboardContext::money($income - $expenses)],
            ],
        ];
    }
}
