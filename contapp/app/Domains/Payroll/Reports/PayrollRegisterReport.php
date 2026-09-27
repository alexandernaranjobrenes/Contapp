<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Reports\Concerns\ResolvesPayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * La planilla íntegra de un período: una línea por trabajador, del bruto al
 * neto y del neto al costo patronal.
 *
 * ── Es el documento que se firma ─────────────────────────────────────────
 *
 * Y por eso lleva las tres cifras que la mayoría de las planillas impresas
 * omiten:
 *
 *   NETO A PAGAR          lo que recibe el trabajador
 *   TOTAL DEDUCCIONES     lo que se le retuvo, desglosado en cargas,
 *                         impuesto y otras
 *   COSTO TOTAL EMPRESA   bruto + cargas patronales + provisiones
 *
 * La última es la que más se omite y la que más cuesta omitir: una empresa que
 * cotiza un proyecto con el bruto se queda corta en más de un 30%, porque las
 * cargas patronales rondan el 26,83% y las provisiones de aguinaldo,
 * vacaciones y cesantía suman otro 17,8% aparte.
 */
class PayrollRegisterReport implements PayrollReport
{
    use ResolvesPayrollPeriod;

    public function code(): string
    {
        return 'payroll-register';
    }

    public function label(): string
    {
        return 'Planilla íntegra por período';
    }

    public function description(): string
    {
        return 'Una línea por trabajador, del salario bruto al neto y al costo total para la empresa.';
    }

    public function decision(): string
    {
        return 'Cuánto se paga, cuánto se retiene y cuánto cuesta realmente la planilla del período.';
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
            new ReportFilter('cost_center_id', 'Centro de costo', ReportFilter::SELECT, optionSource: 'cost_centers'),
            new ReportFilter('department_id', 'Departamento', ReportFilter::SELECT, optionSource: 'departments'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $period = $this->resolvePeriod($company, $filters);

        $columns = [
            new ReportColumn('code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('identification', 'Identificación'),
            new ReportColumn('position', 'Puesto'),
            new ReportColumn('cost_center', 'C. costo'),
            new ReportColumn('days', 'Días', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('gross', 'Salario bruto', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('ccss_base', 'Base de cargas', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('employee_contributions', 'Cargas obreras', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('income_tax', 'Impuesto', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('other_deductions', 'Otras deducciones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('total_deductions', 'Total deducciones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('net_pay', 'Neto a pagar', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('employer_contributions', 'Cargas patronales', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('provisions', 'Provisiones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('employer_cost', 'Costo total empresa', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('payment_method', 'Forma de pago'),
        ];

        if ($period === null) {
            return new ReportResult($columns, [], notes: $this->periodNote(null));
        }

        $query = PayrollEntry::with(['employee:id,code,first_name,last_name1,last_name2,identification_number,position,department_id,job_position_id', 'costCenter:id,code'])
            ->where('payroll_period_id', $period->id);

        if (($filters['cost_center_id'] ?? null) !== null) {
            $query->where('cost_center_id', $filters['cost_center_id']);
        }

        if (($filters['department_id'] ?? null) !== null) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        $rows = $query->get()
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->code)
            ->map(fn (PayrollEntry $e) => [
                'code' => $e->employee?->code,
                'employee' => $e->employee?->fullName(),
                'identification' => $e->employee?->identification_number,
                'position' => $e->employee?->positionLabel(),
                'cost_center' => $e->costCenter?->code,
                'days' => (float) $e->days_worked,
                'gross' => (float) $e->total_earnings,
                'ccss_base' => (float) $e->ccss_base,
                'employee_contributions' => (float) $e->total_employee_contributions,
                'income_tax' => (float) $e->income_tax,
                'other_deductions' => (float) $e->total_other_deductions,
                'total_deductions' => (float) $e->total_deductions,
                'net_pay' => (float) $e->net_pay,
                'employer_contributions' => (float) $e->total_employer_contributions,
                'provisions' => (float) $e->total_provisions,
                'employer_cost' => (float) $e->employerCost(),
                'payment_method' => $e->payment_method,
            ])->values()->all();

        $notes = $this->periodNote($period);

        $notes[] = 'El «costo total empresa» es el bruto más las cargas patronales más las provisiones: '.
            'es lo que hay que usar para cotizar, no el bruto.';

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
