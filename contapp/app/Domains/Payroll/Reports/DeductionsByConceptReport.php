<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;
use Carbon\Carbon;

/**
 * Lo rebajado por cada rubro, con dos formas de verlo.
 *
 * ── Resumen o detalle: son dos preguntas distintas ───────────────────────
 *
 *   RESUMEN   una línea por rubro, con cuánta gente y cuánto total. Es el que
 *             se usa para saber cuánto hay que girarle al banco por pensiones
 *             alimentarias, cuánto a la asociación solidarista, cuánto al
 *             juzgado por embargos.
 *   DETALLE   una línea por trabajador y rubro. Es el que acompaña al giro:
 *             quien recibe el dinero necesita saber de quién es cada monto.
 *
 * Un solo reporte con las dos vistas, y no dos reportes, porque son los mismos
 * datos con distinto nivel de agregación: separarlos garantiza que un día
 * dejen de cuadrar entre sí.
 *
 * ── Qué cuenta como «deducción» ──────────────────────────────────────────
 *
 * Por defecto, solo las OTRAS deducciones: préstamos, adelantos, embargos,
 * cuotas. Las cargas sociales y el impuesto también rebajan el neto, pero
 * tienen sus propios reportes y mezclarlos acá haría que el total de
 * «deducciones» no sirviera ni para una cosa ni para la otra. Se pueden
 * incluir explícitamente con el filtro.
 */
class DeductionsByConceptReport implements PayrollReport
{
    public function code(): string
    {
        return 'deductions-by-concept';
    }

    public function label(): string
    {
        return 'Deducciones por tipo de rubro';
    }

    public function description(): string
    {
        return 'Cuánto se rebajó por cada rubro, en resumen o trabajador por trabajador.';
    }

    public function decision(): string
    {
        return 'Cuánto hay que girar a cada destino —asociación, juzgado, banco— y de quién es cada monto.';
    }

    public function group(): string
    {
        return 'Deducciones y préstamos';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_month',
                hint: 'Por fecha de pago del período.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('view', 'Vista', ReportFilter::SELECT, default: 'summary', options: [
                'summary' => 'Resumen por rubro',
                'detail' => 'Detalle por trabajador',
            ]),
            new ReportFilter('include_statutory', 'Incluir cargas sociales e impuesto', ReportFilter::BOOLEAN,
                hint: 'Tienen su propio reporte; acá suelen estorbar el total.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $from = $filters['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to = $filters['to'] ?? now()->format('Y-m-d');
        $detail = ($filters['view'] ?? 'summary') === 'detail';

        $kinds = ($filters['include_statutory'] ?? false)
            ? PayrollEntryLine::DEDUCTION_KINDS
            : ['deduction'];

        $periods = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->get()
            ->keyBy('id');

        $columns = $detail ? $this->detailColumns() : $this->summaryColumns();

        $notes = [sprintf('Períodos con fecha de pago del %s al %s: %d.',
            Carbon::parse($from)->format('d/m/Y'),
            Carbon::parse($to)->format('d/m/Y'),
            $periods->count())];

        if ($periods->isEmpty()) {
            $notes[] = 'No hay períodos pagados en ese rango.';

            return new ReportResult($columns, [], notes: $notes);
        }

        $entries = PayrollEntry::with(['employee:id,code,first_name,last_name1,last_name2,identification_number', 'lines'])
            ->whereIn('payroll_period_id', $periods->keys())
            ->get();

        $rows = $detail
            ? $this->detailRows($entries, $periods, $kinds)
            : $this->summaryRows($entries, $kinds);

        if (! ($filters['include_statutory'] ?? false)) {
            $notes[] = 'No incluye cargas sociales ni impuesto al salario: cada uno tiene su propio reporte.';
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    /** @return ReportColumn[] */
    private function summaryColumns(): array
    {
        return [
            new ReportColumn('code', 'Rubro'),
            new ReportColumn('name', 'Descripción'),
            new ReportColumn('kind', 'Tipo'),
            new ReportColumn('employees', 'Trabajadores', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('lines', 'Rebajos', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('amount', 'Total rebajado', ReportColumn::MONEY, totalizable: true),
        ];
    }

    /** @return ReportColumn[] */
    private function detailColumns(): array
    {
        return [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('identification', 'Identificación'),
            new ReportColumn('period', 'Período'),
            new ReportColumn('payment_date', 'Fecha de pago', ReportColumn::DATE),
            new ReportColumn('code', 'Rubro'),
            new ReportColumn('name', 'Descripción'),
            new ReportColumn('amount', 'Rebajado', ReportColumn::MONEY, totalizable: true),
        ];
    }

    /**
     * @param  string[]  $kinds
     * @return array<int, array<string, mixed>>
     */
    private function summaryRows(mixed $entries, array $kinds): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            foreach ($entry->lines->whereIn('kind', $kinds) as $line) {
                $key = $line->code;

                $grouped[$key] ??= [
                    'code' => $line->code,
                    'name' => $line->name,
                    'kind' => PayrollEntryLine::KINDS[$line->kind] ?? $line->kind,
                    // Un set, no un contador: el mismo trabajador puede tener
                    // el rubro en varios períodos del rango y contarlo dos
                    // veces diría que hay más gente con préstamo de la que hay.
                    'employee_ids' => [],
                    'lines' => 0,
                    'amount' => '0.00',
                ];

                $grouped[$key]['employee_ids'][$entry->employee_id] = true;
                $grouped[$key]['lines']++;
                $grouped[$key]['amount'] = bcadd($grouped[$key]['amount'], (string) $line->amount, 2);
            }
        }

        ksort($grouped);

        return array_values(array_map(fn (array $row) => [
            'code' => $row['code'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'employees' => count($row['employee_ids']),
            'lines' => $row['lines'],
            'amount' => (float) $row['amount'],
        ], $grouped));
    }

    /**
     * @param  string[]  $kinds
     * @return array<int, array<string, mixed>>
     */
    private function detailRows(mixed $entries, mixed $periods, array $kinds): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $period = $periods[$entry->payroll_period_id] ?? null;

            foreach ($entry->lines->whereIn('kind', $kinds) as $line) {
                $rows[] = [
                    'employee_code' => $entry->employee?->code,
                    'employee' => $entry->employee?->fullName(),
                    'identification' => $entry->employee?->identification_number,
                    'period' => $period?->name,
                    'payment_date' => $period?->payment_date->format('Y-m-d'),
                    'code' => $line->code,
                    'name' => $line->name,
                    'amount' => (float) $line->amount,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['code'], $a['employee_code']]
            <=> [$b['code'], $b['employee_code']]);

        return $rows;
    }
}
