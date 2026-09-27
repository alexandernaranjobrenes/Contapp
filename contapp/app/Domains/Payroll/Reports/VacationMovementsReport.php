<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * El movimiento a movimiento de vacaciones: la tarjeta individual.
 *
 * ── Es el reporte que se le entrega al trabajador ────────────────────────
 *
 * Con un trabajador seleccionado sale su tarjeta: cada acreditación con el
 * período que la generó, cada disfrute con sus fechas, y el saldo CORRIENDO
 * renglón por renglón.
 *
 * El saldo corrido es la columna que importa y la que un listado plano no
 * tiene. Sin ella, comprobar un saldo obliga a sumar treinta renglones a mano;
 * con ella se lee de un vistazo en qué momento el saldo era cuál, que es
 * exactamente lo que se discute cuando alguien reclama días.
 *
 * Por eso el orden es cronológico y NO configurable: un saldo corrido ordenado
 * por otra cosa es un número sin sentido.
 */
class VacationMovementsReport implements PayrollReport
{
    public function code(): string
    {
        return 'vacation-movements';
    }

    public function label(): string
    {
        return 'Vacaciones: tarjeta individual';
    }

    public function description(): string
    {
        return 'Cada movimiento en orden cronológico, con el saldo corriendo renglón por renglón.';
    }

    public function decision(): string
    {
        return 'Cómo se formó el saldo de una persona, para responderle cuando lo reclama.';
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
            new ReportFilter('employee_id', 'Trabajador', ReportFilter::SELECT, optionSource: 'employees',
                hint: 'En blanco trae a todos, agrupados por trabajador.'),
            new ReportFilter('from', 'Desde', ReportFilter::DATE,
                hint: 'Cuidado: con un «desde», el saldo corrido arranca desde cero y no desde el saldo real.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE),
            new ReportFilter('type', 'Tipo de movimiento', ReportFilter::SELECT,
                options: VacationMovement::TYPES),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('movement_date', 'Fecha', ReportColumn::DATE),
            new ReportColumn('type', 'Movimiento'),
            new ReportColumn('from_date', 'Desde', ReportColumn::DATE),
            new ReportColumn('to_date', 'Hasta', ReportColumn::DATE),
            new ReportColumn('days', 'Días', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('running_balance', 'Saldo', ReportColumn::NUMBER),
            new ReportColumn('amount', 'Monto pagado', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('origin', 'Origen'),
            new ReportColumn('notes', 'Notas'),
        ];

        $query = VacationMovement::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with(['employee:id,code,first_name,last_name1,last_name2', 'period:id,name'])
            // Por trabajador y luego por fecha: el saldo corrido exige este
            // orden y no otro.
            ->orderBy('employee_id')
            ->orderBy('movement_date')
            ->orderBy('id');

        foreach (['employee_id', 'type'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('movement_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('movement_date', '<=', $filters['to']);
        }

        $rows = [];
        $running = '0.0000';
        $currentEmployee = null;

        foreach ($query->get() as $movement) {
            // El saldo corrido se reinicia al cambiar de trabajador: si no, el
            // de la segunda persona arrancaría con el saldo de la primera.
            if ($movement->employee_id !== $currentEmployee) {
                $currentEmployee = $movement->employee_id;
                $running = '0.0000';
            }

            $running = bcadd($running, (string) $movement->days, 4);

            $rows[] = [
                'employee_code' => $movement->employee?->code,
                'employee' => $movement->employee?->fullName(),
                'movement_date' => $movement->movement_date->format('Y-m-d'),
                'type' => VacationMovement::TYPES[$movement->type] ?? $movement->type,
                'from_date' => $movement->from_date?->format('Y-m-d'),
                'to_date' => $movement->to_date?->format('Y-m-d'),
                'days' => (float) $movement->days,
                'running_balance' => (float) $running,
                'amount' => $movement->amount === null ? null : (float) $movement->amount,
                'origin' => $this->origin($movement),
                'notes' => $movement->notes,
            ];
        }

        $notes = [
            'El saldo de la última fila de cada trabajador es su saldo actual: los días se guardan en negativo '.
            'cuando rebajan, así que el saldo es la suma.',
        ];

        if (($filters['from'] ?? null) !== null) {
            $notes[] = 'Hay un filtro «desde», así que el saldo corrido arranca en cero en la primera fila '.
                'mostrada y NO coincide con el saldo real del trabajador. Para el saldo real, quitá el filtro '.
                'o usá el reporte de saldo colectivo.';
        }

        if (($filters['type'] ?? null) !== null) {
            $notes[] = 'Con un tipo de movimiento filtrado, el saldo corrido tampoco es el real: le faltan los '.
                'movimientos de los otros tipos.';
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    /**
     * De dónde vino el movimiento. Una acreditación la genera el cálculo de la
     * planilla y una liquidación, la contabilización de la liquidación:
     * distinguirlo explica por qué un movimiento no se puede borrar a mano.
     */
    private function origin(VacationMovement $movement): string
    {
        if ($movement->labor_settlement_id !== null) {
            return 'Liquidación laboral';
        }

        if ($movement->payroll_period_id !== null) {
            return 'Planilla '.($movement->period?->name ?? "#{$movement->payroll_period_id}");
        }

        return 'Registro manual';
    }
}
