<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

/**
 * El costo de las últimas seis planillas aprobadas o contabilizadas: lo
 * devengado por los trabajadores y, encima, las cargas sociales que paga el
 * patrono. Las dos partes apiladas son lo que le cuesta cada planilla a la
 * empresa (sin las provisiones de aguinaldo, vacaciones y cesantía).
 */
class PayrollCostWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'payroll_cost';
    }

    public function title(): string
    {
        return 'Costo de planilla';
    }

    public function description(): string
    {
        return 'Salarios y cargas patronales de las últimas seis planillas aprobadas o contabilizadas.';
    }

    public function section(): string
    {
        return 'payroll';
    }

    public function kind(): string
    {
        return 'columns';
    }

    public function screens(): array
    {
        return ['payroll.periods'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('payroll-periods.index');
    }

    public function data(DashboardContext $context): array
    {
        $periods = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $context->company->id)
            ->whereIn('status', ['approved', 'posted'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->take(6)
            ->get(['id', 'name', 'payment_date'])
            ->reverse()
            ->values();

        $totals = DB::table('payroll_entries')
            ->whereIn('payroll_period_id', $periods->pluck('id'))
            ->groupBy('payroll_period_id')
            ->selectRaw('payroll_period_id,
                COALESCE(SUM(total_earnings), 0) as earnings,
                COALESCE(SUM(total_employer_contributions), 0) as employer')
            ->get()
            ->keyBy('payroll_period_id');

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'mode' => 'stacked',
            'series' => [
                ['key' => 'earnings', 'label' => 'Salarios'],
                ['key' => 'employer', 'label' => 'Cargas patronales'],
            ],
            'points' => $periods->map(fn (PayrollPeriod $period) => [
                'label' => $period->payment_date ? DashboardContext::dayLabel($period->payment_date) : $period->name,
                'title' => $period->name,
                'values' => [
                    'earnings' => DashboardContext::money($totals->get($period->id)?->earnings),
                    'employer' => DashboardContext::money($totals->get($period->id)?->employer),
                ],
            ])->all(),
            'empty' => 'Todavía no hay planillas aprobadas.',
        ];
    }
}
