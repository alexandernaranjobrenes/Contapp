<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Reports\Concerns\ResolvesPayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * La planilla de la Caja: bases salariales y cada componente por separado.
 *
 * ── Por qué las columnas se arman solas y no están escritas ──────────────
 *
 * Porque los componentes cambian. Una columna fija por cada rubro —SEM, IVM,
 * Banco Popular, Asignaciones, INA, IMAS, FCL, ROP…— quedaría desactualizada
 * el día que un decreto agregue, quite o renombre uno, y el reporte seguiría
 * saliendo sin decir que le falta algo.
 *
 * Así que las columnas se arman con los componentes que APARECEN en las
 * boletas del período. Si el período se calculó con nueve rubros patronales,
 * el reporte trae nueve; si un pensionado no cotizó IVM, su celda va vacía en
 * vez de en cero, que no es lo mismo: cero dice «cotizó nada» y vacío dice
 * «no le aplica».
 *
 * ── Este reporte NO lleva netos ni préstamos ─────────────────────────────
 *
 * A propósito. Es el que se manda o se concilia contra la institución, y lo
 * único que le corresponde saber a la Caja son las bases salariales y las
 * cuotas. El neto del trabajador, sus préstamos y sus embargos no son asunto
 * de ella.
 */
class CcssPayrollReport implements PayrollReport
{
    use ResolvesPayrollPeriod;

    public function code(): string
    {
        return 'ccss-payroll';
    }

    public function label(): string
    {
        return 'Planilla de la CCSS';
    }

    public function description(): string
    {
        return 'Bases salariales y cuotas por componente, obrero y patronal, sin netos ni deducciones.';
    }

    public function decision(): string
    {
        return 'Qué se le reporta y se le paga a la Caja, y si cuadra con la planilla que ella devuelve.';
    }

    public function group(): string
    {
        return 'Cargas e impuestos';
    }

    public function frozenColumns(): int
    {
        return 3;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            $this->periodFilter('El período que se le reporta a la Caja.'),
            new ReportFilter('payer', 'Componentes', ReportFilter::SELECT, options: [
                'employee' => 'Solo obreros',
                'employer' => 'Solo patronales',
            ], hint: 'En blanco trae los dos, que es como se concilia.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $period = $this->resolvePeriod($company, $filters);

        $identity = [
            new ReportColumn('ccss_number', 'N.º asegurado'),
            new ReportColumn('identification', 'Identificación'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('days', 'Días', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('salary_base', 'Salario reportado', ReportColumn::MONEY, totalizable: true),
        ];

        if ($period === null) {
            return new ReportResult($identity, [], notes: $this->periodNote(null));
        }

        $entries = PayrollEntry::with(['employee:id,code,ccss_number,identification_number,first_name,last_name1,last_name2,is_pensioner', 'lines'])
            ->where('payroll_period_id', $period->id)
            ->get()
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->ccss_number ?? $e->employee?->code);

        $kinds = match ($filters['payer'] ?? null) {
            'employee' => ['employee_contribution'],
            'employer' => ['employer_contribution'],
            default => ['employee_contribution', 'employer_contribution'],
        };

        // Los componentes que de verdad aparecieron, en el orden de su código:
        // es el orden en que la Caja los lista.
        $components = [];

        foreach ($entries as $entry) {
            foreach ($entry->lines->whereIn('kind', $kinds) as $line) {
                $components[$line->code] = [
                    'name' => $line->name,
                    'kind' => $line->kind,
                ];
            }
        }

        ksort($components);

        $columns = $identity;

        foreach ($components as $code => $component) {
            $columns[] = new ReportColumn(
                'c_'.$code,
                // El sufijo dice de quién es la cuota: sin él, dos columnas de
                // «Enfermedad y Maternidad» se confunden y se paga de menos.
                $component['name'].($component['kind'] === 'employee_contribution' ? ' (obrero)' : ' (patronal)'),
                ReportColumn::MONEY,
                totalizable: true,
            );
        }

        $columns[] = new ReportColumn('total_employee', 'Total obrero', ReportColumn::MONEY, totalizable: true);
        $columns[] = new ReportColumn('total_employer', 'Total patronal', ReportColumn::MONEY, totalizable: true);
        $columns[] = new ReportColumn('total', 'Total a enterar', ReportColumn::MONEY, totalizable: true);

        $rows = [];

        foreach ($entries as $entry) {
            $row = [
                'ccss_number' => $entry->employee?->ccss_number,
                'identification' => $entry->employee?->identification_number,
                'employee' => $entry->employee?->fullName(),
                'days' => (float) $entry->days_worked,
                'salary_base' => (float) $entry->ccss_base,
                'total_employee' => 0.0,
                'total_employer' => 0.0,
            ];

            foreach ($entry->lines->whereIn('kind', $kinds) as $line) {
                $row['c_'.$line->code] = (float) $line->amount;

                $bucket = $line->kind === 'employee_contribution' ? 'total_employee' : 'total_employer';
                $row[$bucket] += (float) $line->amount;
            }

            $row['total'] = $row['total_employee'] + $row['total_employer'];

            $rows[] = $row;
        }

        $notes = $this->periodNote($period);

        $notes[] = 'Una celda VACÍA significa que ese componente no le aplica al trabajador —un pensionado no '.
            'cotiza IVM— y es distinto de un cero.';
        $notes[] = 'El salario reportado es la BASE de cargas, no el bruto: los viáticos, el aguinaldo y los '.
            'subsidios de incapacidad no forman salario y quedan fuera a propósito.';

        if (($filters['payer'] ?? null) === null) {
            $notes[] = 'Este reporte no lleva netos, préstamos ni embargos: a la Caja solo le corresponden las '.
                'bases y las cuotas.';
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
