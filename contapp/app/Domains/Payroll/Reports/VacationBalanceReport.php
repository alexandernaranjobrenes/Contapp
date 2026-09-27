<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Saldo de vacaciones de todo el personal, con su valor en colones.
 *
 * ── Las vacaciones sin disfrutar son un PASIVO, no una estadística ───────
 *
 * Es el punto de este reporte. El saldo en días es una cifra de recursos
 * humanos; la columna de valor es la de contabilidad, y es la que hace visible
 * que la empresa le debe plata a su gente. Una empresa con cuarenta personas y
 * quince días acumulados cada una tiene un pasivo de más de dos meses de
 * planilla que normalmente nadie ha calculado.
 *
 * ── El saldo NO se guarda: es la suma de los movimientos ─────────────────
 *
 * Así que cada día acumulado se puede rastrear hasta el período que lo
 * acreditó, y este reporte se puede cuadrar contra el de movimientos. Por eso
 * también aparecen las columnas de acreditado y disfrutado por separado: un
 * saldo de 10 con 10 acreditados y 0 disfrutados dice algo muy distinto de un
 * saldo de 10 con 40 acreditados y 30 disfrutados.
 *
 * ── El saldo negativo es legítimo, y hay que verlo ───────────────────────
 *
 * Significa que la persona disfrutó días que todavía no había ganado. Puede
 * ser una decisión de la empresa; lo que no puede ser es una sorpresa al
 * liquidar.
 */
class VacationBalanceReport implements PayrollReport
{
    public function code(): string
    {
        return 'vacation-balance';
    }

    public function label(): string
    {
        return 'Vacaciones: saldo colectivo';
    }

    public function description(): string
    {
        return 'Días acumulados, disfrutados y pendientes de cada trabajador, con su valor en colones.';
    }

    public function decision(): string
    {
        return 'Cuánto debe la empresa en vacaciones y a quién hay que mandarlas a disfrutar.';
    }

    public function group(): string
    {
        return 'Vacaciones';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, default: 'active', options: [
                'active' => 'Activos',
                'suspended' => 'Suspendidos',
                'terminated' => 'Salidos',
            ]),
            new ReportFilter('department_id', 'Departamento', ReportFilter::SELECT, optionSource: 'departments'),
            new ReportFilter('cost_center_id', 'Centro de costo', ReportFilter::SELECT, optionSource: 'cost_centers'),
            new ReportFilter('min_balance', 'Saldo mínimo de días', ReportFilter::TEXT,
                hint: 'Para encontrar a quienes acumularon demasiado: escribí 15, por ejemplo.'),
            new ReportFilter('only_negative', 'Solo saldos negativos', ReportFilter::BOOLEAN,
                hint: 'Quienes disfrutaron días que todavía no habían ganado.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('department', 'Departamento'),
            new ReportColumn('cost_center', 'C. costo'),
            new ReportColumn('hire_date', 'Ingreso', ReportColumn::DATE),
            new ReportColumn('years_of_service', 'Antigüedad', ReportColumn::NUMBER),
            new ReportColumn('accrued', 'Días acreditados', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('taken', 'Días disfrutados', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('paid', 'Días pagados', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('balance', 'Saldo en días', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('daily_rate', 'Valor del día', ReportColumn::MONEY),
            new ReportColumn('liability', 'Valor del saldo', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('last_movement', 'Último movimiento', ReportColumn::DATE),
        ];

        $query = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with(['costCenter:id,code', 'departmentRecord:id,name'])
            ->orderBy('code');

        foreach (['status', 'department_id', 'cost_center_id'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        $employees = $query->get();

        $movements = VacationMovement::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->groupBy('employee_id');

        $minBalance = ($filters['min_balance'] ?? null) !== null && is_numeric($filters['min_balance'])
            ? (string) $filters['min_balance']
            : null;

        $rows = [];

        foreach ($employees as $employee) {
            $own = $movements[$employee->id] ?? collect();

            $balance = $own->reduce(
                fn (string $c, VacationMovement $m) => bcadd($c, (string) $m->days, 4), '0.0000'
            );

            if (($filters['only_negative'] ?? false) && bccomp($balance, '0.0000', 4) >= 0) {
                continue;
            }

            if ($minBalance !== null && bccomp($balance, $minBalance, 4) < 0) {
                continue;
            }

            $daily = $employee->dailyRate();

            $rows[] = [
                'code' => $employee->code,
                'employee' => $employee->fullName(),
                'department' => $employee->departmentLabel(),
                'cost_center' => $employee->costCenter?->code,
                'hire_date' => $employee->hire_date->format('Y-m-d'),
                'years_of_service' => $employee->yearsOfService(now()),
                'accrued' => (float) $this->daysOf($own, ['accrual', 'adjustment']),
                // En positivo para que se lean como cantidades: se guardan en
                // negativo porque el saldo es la suma.
                'taken' => (float) bcmul($this->daysOf($own, ['taken']), '-1', 4),
                'paid' => (float) bcmul($this->daysOf($own, ['paid', 'settlement']), '-1', 4),
                'balance' => (float) $balance,
                'daily_rate' => (float) $daily,
                'liability' => (float) bcmul($balance, $daily, 2),
                'last_movement' => $own->max('movement_date')?->format('Y-m-d'),
            ];
        }

        $notes = [
            'El «valor del saldo» es lo que costaría pagar hoy lo acumulado: es un pasivo real, no una '.
            'estadística.',
            'El saldo es la SUMA de los movimientos, no un campo: cada día acreditado se puede rastrear '.
            'hasta el período que lo generó.',
        ];

        $negative = count(array_filter($rows, fn (array $r) => $r['balance'] < 0));

        if ($negative > 0 && ! ($filters['only_negative'] ?? false)) {
            $notes[] = sprintf(
                '%d trabajador(es) tienen saldo negativo: disfrutaron días que todavía no habían ganado. '.
                'Es legítimo si fue una decisión, pero no puede ser una sorpresa al liquidar.',
                $negative
            );
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    /**
     * @param  string[]  $types
     */
    private function daysOf(mixed $movements, array $types): string
    {
        return $movements->whereIn('type', $types)->reduce(
            fn (string $c, VacationMovement $m) => bcadd($c, (string) $m->days, 4), '0.0000'
        );
    }
}
