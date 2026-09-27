<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Costo de planilla agrupado por centro de costo o por departamento.
 *
 * ── La cifra que este reporte existe para mostrar ────────────────────────
 *
 * El RECARGO: cuánto por encima del salario bruto cuesta la planilla. Es la
 * suma de las cargas patronales y las provisiones dividida entre el bruto, y
 * ronda el 45% en Costa Rica —26,83% de cargas más unos 17,8% de aguinaldo,
 * vacaciones y cesantía—.
 *
 * Quien cotiza un proyecto con el salario bruto se queda corto en esa
 * proporción, y es el error más caro que se puede cometer con un reporte de
 * planilla. Por eso el recargo va como columna propia y no como algo que haya
 * que calcular mentalmente.
 *
 * ── Centro de costo o departamento, no los dos a la vez ──────────────────
 *
 * Son dos cortes distintos de lo mismo y sirven a dos preguntas: el centro de
 * costo es contable —cuadra contra el mayor— y el departamento es
 * organizacional —sirve para hablar con las jefaturas—. Mostrarlos juntos daría
 * una tabla que no suma bien por ninguno de los dos.
 */
class EmployerCostReport implements PayrollReport
{
    public function code(): string
    {
        return 'employer-cost';
    }

    public function label(): string
    {
        return 'Costo patronal por centro de costo';
    }

    public function description(): string
    {
        return 'Bruto, cargas patronales, provisiones y el recargo sobre el salario, agrupado.';
    }

    public function decision(): string
    {
        return 'Cuánto cuesta de verdad cada área, y con qué recargo hay que cotizar una hora de trabajo.';
    }

    public function group(): string
    {
        return 'Costos y pasivo laboral';
    }

    public function frozenColumns(): int
    {
        return 1;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_year',
                hint: 'Por fecha de fin de período.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('group_by', 'Agrupar por', ReportFilter::SELECT, default: 'cost_center', options: [
                'cost_center' => 'Centro de costo (contable)',
                'department' => 'Departamento (organizacional)',
            ]),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $byDepartment = ($filters['group_by'] ?? 'cost_center') === 'department';

        $columns = [
            new ReportColumn('group', $byDepartment ? 'Departamento' : 'Centro de costo'),
            new ReportColumn('employees', 'Trabajadores', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('gross', 'Salario bruto', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('employer_contributions', 'Cargas patronales', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('provisions', 'Provisiones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_cost', 'Costo total', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('surcharge', 'Recargo sobre el bruto', ReportColumn::PERCENT),
            new ReportColumn('net_paid', 'Neto pagado', ReportColumn::MONEY, totalizable: true),
        ];

        $periodIds = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->when(($filters['from'] ?? null) !== null,
                fn ($q) => $q->whereDate('end_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null,
                fn ($q) => $q->whereDate('end_date', '<=', $filters['to']))
            ->pluck('id');

        $notes = [];

        if ($periodIds->isEmpty()) {
            $notes[] = 'No hay planillas calculadas en ese rango.';

            return new ReportResult($columns, [], notes: $notes);
        }

        $entries = PayrollEntry::with(['costCenter:id,code,name', 'employee:id,department_id,department'])
            ->whereIn('payroll_period_id', $periodIds)
            ->get();

        $departments = Department::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->pluck('name', 'id');

        $grouped = [];
        $withoutGroup = 0;

        foreach ($entries as $entry) {
            $label = $byDepartment
                ? ($departments[$entry->employee?->department_id] ?? $entry->employee?->department)
                : ($entry->costCenter === null ? null : $entry->costCenter->code.' — '.$entry->costCenter->name);

            if ($label === null || $label === '') {
                $label = $byDepartment ? 'Sin departamento' : 'Sin centro de costo';
                $withoutGroup++;
            }

            $grouped[$label] ??= [
                'group' => $label,
                'employee_ids' => [],
                'gross' => '0.00',
                'employer_contributions' => '0.00',
                'provisions' => '0.00',
                'net_paid' => '0.00',
            ];

            $grouped[$label]['employee_ids'][$entry->employee_id] = true;
            $grouped[$label]['gross'] = bcadd($grouped[$label]['gross'], (string) $entry->total_earnings, 2);
            $grouped[$label]['employer_contributions'] = bcadd(
                $grouped[$label]['employer_contributions'], (string) $entry->total_employer_contributions, 2
            );
            $grouped[$label]['provisions'] = bcadd(
                $grouped[$label]['provisions'], (string) $entry->total_provisions, 2
            );
            $grouped[$label]['net_paid'] = bcadd($grouped[$label]['net_paid'], (string) $entry->net_pay, 2);
        }

        ksort($grouped);

        $rows = [];

        foreach ($grouped as $group) {
            $overhead = bcadd($group['employer_contributions'], $group['provisions'], 2);
            $total = bcadd($group['gross'], $overhead, 2);

            $rows[] = [
                'group' => $group['group'],
                // Un set y no un contador: la misma persona aparece en cada
                // período del rango y contarla una vez por boleta diría que
                // hay diez veces más gente de la que hay.
                'employees' => count($group['employee_ids']),
                'gross' => (float) $group['gross'],
                'employer_contributions' => (float) $group['employer_contributions'],
                'provisions' => (float) $group['provisions'],
                'total_cost' => (float) $total,
                'surcharge' => bccomp($group['gross'], '0.00', 2) > 0
                    ? (float) bcmul(bcdiv($overhead, $group['gross'], 6), '100', 4)
                    : null,
                'net_paid' => (float) $group['net_paid'],
            ];
        }

        // El recargo del CONJUNTO no es la suma de los recargos ni su
        // promedio: es el total de sobrecosto sobre el total de bruto. Se
        // declara para que el pie no sume porcentajes.
        $totalGross = array_sum(array_column($rows, 'gross'));
        $totalOverhead = array_sum(array_column($rows, 'employer_contributions'))
            + array_sum(array_column($rows, 'provisions'));

        $totals = $totalGross > 0
            ? ['surcharge' => round($totalOverhead / $totalGross * 100, 4)]
            : [];

        $notes[] = 'El «recargo» es cuánto cuesta la planilla POR ENCIMA del salario bruto. Es la cifra con la '.
            'que hay que cotizar: quien cotiza con el bruto se queda corto en esa proporción.';
        $notes[] = 'El «trabajadores» cuenta personas distintas en el rango, no boletas.';

        if ($withoutGroup > 0) {
            $notes[] = $byDepartment
                ? 'Hay boletas de trabajadores sin departamento: el costo de esa área no se puede repartir.'
                : 'Hay boletas sin centro de costo, así que ese gasto no queda distribuido en la contabilidad.';
        }

        return new ReportResult($columns, $rows, $totals, $notes);
    }
}
