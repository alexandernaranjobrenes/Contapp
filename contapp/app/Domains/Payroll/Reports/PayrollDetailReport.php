<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Reports\Concerns\ResolvesPayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Cada línea de cada boleta del período, con su base y su tasa.
 *
 * ── Es el reporte que se usa cuando alguien reclama ──────────────────────
 *
 * «¿Por qué me rebajaron esto?» no se contesta con la planilla resumida: se
 * contesta con la línea, su base y su tasa. Que las tres estén congeladas en
 * la boleta —y no recalculadas al abrir el reporte— es lo que permite
 * explicar un rebajo de hace ocho meses con las tasas de hace ocho meses.
 *
 * El signo es lo que más se malinterpreta de una boleta, así que la columna de
 * tipo dice explícitamente si la línea SUMA o RESTA del neto, en vez de dejar
 * que se deduzca del nombre del concepto.
 */
class PayrollDetailReport implements PayrollReport
{
    use ResolvesPayrollPeriod;

    public function code(): string
    {
        return 'payroll-detail';
    }

    public function label(): string
    {
        return 'Detalle de boletas por período';
    }

    public function description(): string
    {
        return 'Cada línea de cada boleta con su base, su tasa y su monto.';
    }

    public function decision(): string
    {
        return 'De dónde salió un rebajo o un ingreso concreto, cuando un trabajador lo reclama.';
    }

    public function group(): string
    {
        return 'Planilla del período';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            $this->periodFilter(),
            new ReportFilter('employee_id', 'Trabajador', ReportFilter::SELECT, optionSource: 'employees',
                hint: 'En blanco trae a todos.'),
            new ReportFilter('kind', 'Tipo de línea', ReportFilter::SELECT,
                options: PayrollEntryLine::KINDS),
            new ReportFilter('code', 'Código del concepto', ReportFilter::TEXT,
                hint: 'Parte del código: «PRESTAMO», «do0», «EXTRA».'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $period = $this->resolvePeriod($company, $filters);

        $columns = [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('kind', 'Tipo'),
            new ReportColumn('effect', 'Efecto en el neto'),
            new ReportColumn('code', 'Concepto'),
            new ReportColumn('name', 'Descripción'),
            new ReportColumn('quantity', 'Cantidad', ReportColumn::NUMBER),
            new ReportColumn('base_amount', 'Base', ReportColumn::MONEY),
            new ReportColumn('rate', 'Tasa %', ReportColumn::PERCENT),
            new ReportColumn('amount', 'Monto', ReportColumn::MONEY, totalizable: true),
        ];

        if ($period === null) {
            return new ReportResult($columns, [], notes: $this->periodNote(null));
        }

        $entries = PayrollEntry::with(['employee:id,code,first_name,last_name1,last_name2', 'lines'])
            ->where('payroll_period_id', $period->id)
            ->when(($filters['employee_id'] ?? null) !== null,
                fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->get()
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->code);

        $rows = [];

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                if (($filters['kind'] ?? null) !== null && $line->kind !== $filters['kind']) {
                    continue;
                }

                if (($filters['code'] ?? null) !== null
                    && ! str_contains(mb_strtolower($line->code), mb_strtolower((string) $filters['code']))) {
                    continue;
                }

                $rows[] = [
                    'employee_code' => $entry->employee?->code,
                    'employee' => $entry->employee?->fullName(),
                    'kind' => PayrollEntryLine::KINDS[$line->kind] ?? $line->kind,
                    'effect' => $this->effect($line),
                    'code' => $line->code,
                    'name' => $line->name,
                    'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                    'base_amount' => $line->base_amount === null ? null : (float) $line->base_amount,
                    'rate' => $line->rate === null ? null : (float) $line->rate,
                    'amount' => (float) $line->amount,
                ];
            }
        }

        $notes = $this->periodNote($period);

        $notes[] = 'La base y la tasa son las que estaban vigentes cuando se calculó la boleta, no las de hoy: '.
            'es lo que permite explicar un rebajo viejo con las reglas de su momento.';

        // El total de la columna de monto NO es un número útil si se mezclan
        // tipos: suma ingresos con deducciones y con costo patronal. Se dice,
        // en vez de quitar el total y dejar a quien filtra por un solo tipo
        // sin su suma.
        if (($filters['kind'] ?? null) === null) {
            $notes[] = 'El total de la columna «Monto» solo tiene sentido si se filtra por un tipo de línea: '.
                'sin filtrar suma ingresos, deducciones y costo patronal, que no se suman entre sí.';
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    /**
     * Si la línea suma o resta del neto del trabajador. Las patronales no
     * tocan su neto, y decirlo evita la lectura de que «le rebajaron» el
     * 26,83% de cargas del patrono.
     */
    private function effect(PayrollEntryLine $line): string
    {
        if (in_array($line->kind, PayrollEntryLine::DEDUCTION_KINDS, true)) {
            return 'Resta';
        }

        if ($line->kind === 'earning') {
            return bccomp((string) $line->amount, '0.00', 2) < 0 ? 'Resta' : 'Suma';
        }

        return 'No toca el neto (costo patronal)';
    }
}
