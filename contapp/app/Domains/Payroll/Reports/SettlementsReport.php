<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\LaborSettlementLine;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Las liquidaciones laborales, con cada extremo en su columna.
 *
 * ── Por qué el aguinaldo, las vacaciones, el preaviso y la cesantía van
 *    separados y no en un total ──────────────────────────────────────────
 *
 * Porque cada uno se discute aparte y cada uno se contabiliza aparte. Un total
 * de «liquidaciones del año» sirve para el flujo de caja y para nada más: a la
 * hora de revisar si la empresa está liquidando bien, lo que importa es la
 * proporción entre extremos. Una tanda de salidas donde nadie cobró cesantía
 * significa que todas fueron renuncias o despidos con justa causa —posible,
 * pero digno de mirar— y eso solo se ve con las columnas separadas.
 *
 * ── Las anuladas aparecen, tachadas ──────────────────────────────────────
 *
 * Una liquidación anulada no se borra: tiene un asiento de reversión y forma
 * parte del expediente. Esconderla del reporte haría que los números del
 * reporte no se puedan reconciliar con la contabilidad, que sí la tiene. Por
 * eso el filtro de estado existe y el total advierte cuando las incluye.
 */
class SettlementsReport implements PayrollReport
{
    public function code(): string
    {
        return 'settlements';
    }

    public function label(): string
    {
        return 'Liquidaciones laborales';
    }

    public function description(): string
    {
        return 'Cada liquidación con su causal, sus bases y cada extremo pagado por separado.';
    }

    public function decision(): string
    {
        return 'Cuánto costaron las salidas, por qué causal, y si los extremos pagados corresponden.';
    }

    public function group(): string
    {
        return 'Terminación';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Salidas desde', ReportFilter::DATE, default: 'first_day_of_year'),
            new ReportFilter('to', 'Salidas hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('reason', 'Causal', ReportFilter::SELECT, options: LaborSettlement::REASONS),
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, options: LaborSettlement::STATUSES,
                hint: 'En blanco trae todas, incluidas las anuladas.'),
            new ReportFilter('exclude_voided', 'Excluir anuladas', ReportFilter::BOOLEAN,
                hint: 'Para el costo real del período; para conciliar con contabilidad, dejalas.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('termination_date', 'Salida', ReportColumn::DATE),
            new ReportColumn('reason', 'Causal'),
            new ReportColumn('years_of_service', 'Antigüedad', ReportColumn::NUMBER),
            new ReportColumn('average_daily_salary', 'Valor del día', ReportColumn::MONEY),
            new ReportColumn('christmas_bonus', 'Aguinaldo', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('vacation', 'Vacaciones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('vacation_days', 'Días de vacaciones', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('notice', 'Preaviso', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('severance', 'Cesantía', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('severance_days', 'Días de cesantía', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('indemnity', 'Indemnización', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('pending_salary', 'Salarios pendientes', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_gross', 'Bruto', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_ccss', 'Cargas', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_income_tax', 'Impuesto', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_other_deductions', 'Deducciones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_net', 'Neto pagado', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('bases_from_history', 'Bases del historial'),
            new ReportColumn('status', 'Estado'),
        ];

        $query = LaborSettlement::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with(['employee:id,code,first_name,last_name1,last_name2', 'lines'])
            ->orderBy('termination_date')->orderBy('id');

        if (($filters['from'] ?? null) !== null) {
            $query->where('termination_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('termination_date', '<=', $filters['to']);
        }

        foreach (['reason', 'status'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        if ($filters['exclude_voided'] ?? false) {
            $query->where('status', '!=', 'voided');
        }

        $settlements = $query->get();

        $rows = $settlements->map(fn (LaborSettlement $s) => [
            'employee_code' => $s->employee?->code,
            'employee' => $s->employee?->fullName(),
            'termination_date' => $s->termination_date->format('Y-m-d'),
            'reason' => LaborSettlement::REASONS[$s->reason] ?? $s->reason,
            'years_of_service' => (float) $s->years_of_service,
            'average_daily_salary' => (float) $s->average_daily_salary,
            'christmas_bonus' => $this->amountOf($s, 'christmas_bonus'),
            'vacation' => $this->amountOf($s, 'vacation'),
            'vacation_days' => $this->daysOf($s, 'vacation'),
            'notice' => $this->amountOf($s, 'notice'),
            'severance' => $this->amountOf($s, 'severance'),
            'severance_days' => $this->daysOf($s, 'severance'),
            'indemnity' => $this->amountOf($s, 'indemnity'),
            'pending_salary' => $this->amountOf($s, 'pending_salary'),
            'total_gross' => (float) $s->total_gross,
            'total_ccss' => (float) $s->total_ccss,
            'total_income_tax' => (float) $s->total_income_tax,
            'total_other_deductions' => (float) $s->total_other_deductions,
            'total_net' => (float) $s->total_net,
            // Una liquidación calculada sin historial pagó con el salario de
            // la ficha, y eso casi siempre es de menos.
            'bases_from_history' => $s->bases_from_history
                ? $s->history_months_found.' mes(es)'
                : 'NO — salario de la ficha',
            'status' => LaborSettlement::STATUSES[$s->status] ?? $s->status,
        ])->all();

        $notes = [
            'Las cargas sociales se cobran solo sobre las vacaciones y los salarios pendientes: la cesantía, '.
            'el preaviso y el aguinaldo están exentos.',
        ];

        $withoutHistory = $settlements->where('bases_from_history', false)->count();

        if ($withoutHistory > 0) {
            $notes[] = sprintf(
                '%d liquidación(es) se calcularon con el salario de la ficha y no con el promedio de lo '.
                'devengado, por falta de historial de planillas. Si esas personas tenían horas extra o '.
                'comisiones, se les pagó de menos.',
                $withoutHistory
            );
        }

        $voided = $settlements->where('status', 'voided')->count();

        if ($voided > 0) {
            $notes[] = sprintf(
                'Incluye %d liquidación(es) ANULADAS, que suman en los totales. Para el costo real del '.
                'período, marcá «excluir anuladas»; para conciliar contra contabilidad, dejalas.',
                $voided
            );
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    private function amountOf(LaborSettlement $settlement, string $kind): ?float
    {
        $lines = $settlement->lines->where('kind', $kind);

        return $lines->isEmpty()
            ? null
            : (float) $lines->reduce(
                fn (string $c, LaborSettlementLine $l) => bcadd($c, (string) $l->amount, 2), '0.00'
            );
    }

    private function daysOf(LaborSettlement $settlement, string $kind): ?float
    {
        $line = $settlement->lines->firstWhere('kind', $kind);

        return $line?->days === null ? null : (float) $line->days;
    }
}
