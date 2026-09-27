<?php

namespace App\Domains\Payroll\Reports;

/**
 * El índice de reportes de planilla.
 *
 * Agregar uno es escribir su clase y sumarla a esta lista: el controlador, la
 * pantalla, el XLSX, el PDF y la impresión trabajan contra el contrato.
 *
 * En código y no en tabla, igual que el registro de inventario y que
 * ReportCatalog: un reporte es código, y un registro en base de datos que
 * apunte a una clase que no existe es un error que nadie ve hasta que alguien
 * lo abre.
 */
class PayrollReportRegistry
{
    /** @var array<string, class-string<PayrollReport>> */
    private const REPORTS = [
        'employees' => EmployeeRosterReport::class,
        'payroll-register' => PayrollRegisterReport::class,
        'payroll-detail' => PayrollDetailReport::class,
        'ccss-payroll' => CcssPayrollReport::class,
        'income-tax' => IncomeTaxWithholdingReport::class,
        'deductions-by-concept' => DeductionsByConceptReport::class,
        'loans-savings' => LoansSavingsReport::class,
        'vacation-balance' => VacationBalanceReport::class,
        'vacation-movements' => VacationMovementsReport::class,
        'settlements' => SettlementsReport::class,
        'personnel-actions' => PersonnelActionsReport::class,
        'provisions' => ProvisionLiabilityReport::class,
        'employer-cost' => EmployerCostReport::class,
    ];

    /**
     * El orden del índice: de lo que se consulta todos los días a lo que se
     * consulta al cerrar.
     *
     * @var string[]
     */
    public const GROUP_ORDER = [
        'Personal',
        'Planilla del período',
        'Cargas e impuestos',
        'Deducciones y préstamos',
        'Vacaciones',
        'Terminación',
        'Costos y pasivo laboral',
    ];

    /** @return PayrollReport[] */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $code): ?PayrollReport
    {
        $class = self::REPORTS[$code] ?? null;

        return $class === null ? null : app($class);
    }

    public function has(string $code): bool
    {
        return isset(self::REPORTS[$code]);
    }

    /**
     * Los reportes agrupados y en el orden del índice.
     *
     * @return array<string, PayrollReport[]>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach (self::GROUP_ORDER as $group) {
            $grouped[$group] = [];
        }

        foreach ($this->all() as $report) {
            $grouped[$report->group()][] = $report;
        }

        return array_filter($grouped, fn (array $reports) => $reports !== []);
    }
}
