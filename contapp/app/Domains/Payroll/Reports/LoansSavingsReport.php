<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\EmployeeDeductionApplication;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Control de préstamos y ahorros: cuánto se prestó, cuánto se ha rebajado y
 * cuánto queda.
 *
 * ── El saldo es un campo, y hay que poder auditarlo ──────────────────────
 *
 * A diferencia del saldo de vacaciones —que es la suma de sus movimientos— el
 * saldo de una obligación SÍ se guarda, porque cada planilla lo va bajando. Un
 * campo que se actualiza es un campo que puede quedar mal.
 *
 * Por eso el reporte no se limita a mostrarlo: muestra al lado la suma de lo
 * efectivamente aplicado en planillas, y la diferencia entre el saldo esperado
 * (original − aplicado) y el saldo guardado. Cuando esa columna no es cero, hay
 * algo que revisar: un ajuste hecho a mano, un período borrado sin devolver el
 * saldo, una migración incompleta.
 *
 * Es el mismo criterio que la conciliación del kardex contra el mayor: un
 * número que se mantiene solo tiene que poderse comprobar contra los
 * movimientos que lo movieron.
 *
 * ── Ahorros y préstamos en el mismo reporte ──────────────────────────────
 *
 * Porque mecánicamente son lo mismo —un saldo que la planilla mueve— y quien
 * los administra es la misma persona. El filtro por tipo separa lo que haya
 * que separar.
 */
class LoansSavingsReport implements PayrollReport
{
    public function code(): string
    {
        return 'loans-savings';
    }

    public function label(): string
    {
        return 'Control de préstamos y ahorros';
    }

    public function description(): string
    {
        return 'Monto original, lo rebajado hasta hoy, el saldo y si el saldo cuadra con los rebajos.';
    }

    public function decision(): string
    {
        return 'Cuánto le queda por pagar a cada quien, y si algún saldo quedó descuadrado.';
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
            new ReportFilter('type', 'Tipo', ReportFilter::SELECT, options: EmployeeDeduction::TYPES),
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, default: 'active', options: [
                'active' => 'Vigentes',
                'suspended' => 'Suspendidas',
                'settled' => 'Liquidadas',
                'cancelled' => 'Anuladas',
            ], hint: 'En blanco trae todas, incluidas las ya pagadas.'),
            new ReportFilter('employee_id', 'Trabajador', ReportFilter::SELECT, optionSource: 'employees'),
            new ReportFilter('only_mismatched', 'Solo saldos descuadrados', ReportFilter::BOOLEAN,
                hint: 'Donde el saldo guardado no coincide con original menos aplicado.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('type', 'Tipo'),
            new ReportColumn('reference', 'Referencia'),
            new ReportColumn('description', 'Descripción'),
            new ReportColumn('start_date', 'Desde', ReportColumn::DATE),
            new ReportColumn('end_date', 'Hasta', ReportColumn::DATE),
            new ReportColumn('original_amount', 'Monto original', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('applied', 'Rebajado hasta hoy', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('installments', 'N.º de rebajos', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('installment_amount', 'Cuota', ReportColumn::MONEY),
            new ReportColumn('balance', 'Saldo', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('expected_balance', 'Saldo esperado', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('difference', 'Diferencia', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('last_applied', 'Último rebajo', ReportColumn::DATE),
            new ReportColumn('status', 'Estado'),
        ];

        $query = EmployeeDeduction::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with('employee:id,code,first_name,last_name1,last_name2')
            ->orderBy('employee_id')->orderBy('id');

        foreach (['type', 'status', 'employee_id'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        $deductions = $query->get();

        // Lo aplicado sale de las aplicaciones, no del saldo: es justamente lo
        // que se está comprobando.
        $applications = EmployeeDeductionApplication::whereIn('employee_deduction_id', $deductions->pluck('id'))
            ->get()
            ->groupBy('employee_deduction_id');

        $rows = [];
        $mismatched = 0;

        foreach ($deductions as $deduction) {
            $applied = ($applications[$deduction->id] ?? collect())
                ->reduce(fn (string $c, EmployeeDeductionApplication $a) => bcadd($c, (string) $a->amount, 2), '0.00');

            $expected = bcsub((string) $deduction->original_amount, $applied, 2);
            $difference = bcsub((string) $deduction->balance, $expected, 2);

            $isMismatched = bccomp($difference, '0.00', 2) !== 0;

            if ($isMismatched) {
                $mismatched++;
            }

            if (($filters['only_mismatched'] ?? false) && ! $isMismatched) {
                continue;
            }

            $rows[] = [
                'employee_code' => $deduction->employee?->code,
                'employee' => $deduction->employee?->fullName(),
                'type' => EmployeeDeduction::TYPES[$deduction->type] ?? $deduction->type,
                'reference' => $deduction->reference,
                'description' => $deduction->description,
                'start_date' => $deduction->start_date?->format('Y-m-d'),
                'end_date' => $deduction->end_date?->format('Y-m-d'),
                'original_amount' => (float) $deduction->original_amount,
                'applied' => (float) $applied,
                'installments' => ($applications[$deduction->id] ?? collect())->count(),
                'installment_amount' => $deduction->installment_amount === null
                    ? null
                    : (float) $deduction->installment_amount,
                'balance' => (float) $deduction->balance,
                'expected_balance' => (float) $expected,
                'difference' => (float) $difference,
                'last_applied' => ($applications[$deduction->id] ?? collect())
                    ->max('applied_on')?->format('Y-m-d'),
                'status' => $deduction->status,
            ];
        }

        $notes = [
            'El saldo de una obligación SÍ se guarda —cada planilla lo baja— así que se comprueba: '.
            '«saldo esperado» es el original menos lo aplicado, y la «diferencia» debería ser cero.',
        ];

        if ($mismatched > 0) {
            $notes[] = sprintf(
                '%d obligación(es) tienen el saldo descuadrado respecto de sus rebajos. Suele ser un ajuste '.
                'hecho a mano o un período borrado sin devolver el saldo: conviene revisarlas antes de '.
                'calcular la próxima planilla.',
                $mismatched
            );
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
