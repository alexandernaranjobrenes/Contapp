<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;
use Carbon\Carbon;

/**
 * Retenciones de impuesto al salario, acumuladas por MES.
 *
 * ── Por qué por mes y no por período ─────────────────────────────────────
 *
 * Porque la escala del impuesto es mensual. Con planilla quincenal, la
 * retención de la primera quincena y la de la segunda NO son dos cálculos
 * independientes: la segunda se calcula sobre el acumulado del mes y resta lo
 * ya retenido. Mirar una quincena sola da un número que no se puede explicar
 * ni comparar con la escala.
 *
 * Es también la forma en que se declara: la retención que se entera a
 * Hacienda es la del mes.
 *
 * ── La columna de crédito importa tanto como la del impuesto ─────────────
 *
 * Los créditos por cónyuge e hijos menores se restan del IMPUESTO, no de la
 * base. Verlos en su propia columna es lo que permite detectar la ficha en
 * que alguien olvidó marcar el cónyuge: aparece con crédito cero al lado de
 * compañeros con el mismo salario y crédito lleno.
 */
class IncomeTaxWithholdingReport implements PayrollReport
{
    public function code(): string
    {
        return 'income-tax';
    }

    public function label(): string
    {
        return 'Retenciones de impuesto al salario';
    }

    public function description(): string
    {
        return 'Base gravable, créditos familiares e impuesto retenido, acumulado por mes.';
    }

    public function decision(): string
    {
        return 'Cuánto impuesto se retuvo en el mes y a quién no se le están aplicando sus créditos.';
    }

    public function group(): string
    {
        return 'Cargas e impuestos';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('month', 'Mes', ReportFilter::DATE, default: 'first_day_of_month',
                hint: 'Cualquier día del mes: se toma el mes completo.'),
            new ReportFilter('only_withheld', 'Solo a quienes se les retuvo', ReportFilter::BOOLEAN,
                hint: 'Deja fuera a quien no alcanzó el tramo exento, que es la mayoría.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $anchor = Carbon::parse($filters['month'] ?? now()->format('Y-m-d'));

        $columns = [
            new ReportColumn('code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('identification', 'Identificación'),
            new ReportColumn('periods', 'Períodos del mes', ReportColumn::NUMBER),
            new ReportColumn('gross', 'Devengado del mes', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('taxable_base', 'Base gravable', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('spouse_credit', 'Crédito cónyuge'),
            new ReportColumn('children', 'Hijos con crédito', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('income_tax', 'Impuesto retenido', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('effective_rate', 'Tasa efectiva', ReportColumn::PERCENT),
        ];

        $periodIds = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->whereDate('end_date', '>=', $anchor->copy()->startOfMonth()->format('Y-m-d'))
            ->whereDate('end_date', '<=', $anchor->copy()->endOfMonth()->format('Y-m-d'))
            ->pluck('id');

        $notes = [sprintf('Mes de %s. %d período(s) calculados en el mes.',
            $anchor->translatedFormat('F Y'), $periodIds->count())];

        if ($periodIds->isEmpty()) {
            $notes[] = 'No hay períodos calculados en ese mes.';

            return new ReportResult($columns, [], notes: $notes);
        }

        $entries = PayrollEntry::with('employee:id,code,first_name,last_name1,last_name2,identification_number,has_spouse_credit,children_credit_count')
            ->whereIn('payroll_period_id', $periodIds)
            ->get()
            ->groupBy('employee_id');

        $rows = [];

        foreach ($entries as $employeeEntries) {
            $employee = $employeeEntries->first()->employee;

            $gross = $this->sum($employeeEntries, 'total_earnings');
            $base = $this->sum($employeeEntries, 'income_tax_base');
            $tax = $this->sum($employeeEntries, 'income_tax');

            if (($filters['only_withheld'] ?? false) && bccomp($tax, '0.00', 2) <= 0) {
                continue;
            }

            $rows[] = [
                'code' => $employee?->code,
                'employee' => $employee?->fullName(),
                'identification' => $employee?->identification_number,
                'periods' => $employeeEntries->count(),
                'gross' => (float) $gross,
                'taxable_base' => (float) $base,
                'spouse_credit' => $employee?->has_spouse_credit ? 'Sí' : 'No',
                'children' => $employee?->children_credit_count ?? 0,
                'income_tax' => (float) $tax,
                // Sobre el devengado, que es lo que el trabajador reconoce
                // como su salario: la tasa sobre la base gravable no le dice
                // nada a nadie.
                'effective_rate' => bccomp($gross, '0.00', 2) > 0
                    ? (float) bcmul(bcdiv($tax, $gross, 6), '100', 4)
                    : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => ($a['code'] ?? '') <=> ($b['code'] ?? ''));

        $notes[] = 'El impuesto es del MES completo, no de un período: la escala es mensual y la segunda '.
            'quincena se calcula sobre el acumulado restando lo ya retenido.';
        $notes[] = 'Los créditos por cónyuge e hijos se restan del impuesto, no de la base. Un crédito en «No» '.
            'junto a compañeros con el mismo salario y crédito lleno suele ser una ficha sin marcar.';

        return new ReportResult($columns, $rows, notes: $notes);
    }

    private function sum(mixed $entries, string $field): string
    {
        return $entries->reduce(
            fn (string $carry, PayrollEntry $e) => bcadd($carry, (string) $e->{$field}, 2),
            '0.00'
        );
    }
}
