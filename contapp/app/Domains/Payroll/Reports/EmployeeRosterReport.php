<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Reporting\Reports\HasSelectableColumns;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * La lista de empleados, con las columnas que el usuario escoja.
 *
 * ── Por qué este reporte tiene columnas escogibles y los demás no ────────
 *
 * Porque no existe un juego correcto de columnas para una lista de personal.
 * La misma lista sirve para cuatro cosas distintas y cada una necesita otras
 * columnas:
 *
 *   · preparar carnés            cédula, puesto, fecha de ingreso, foto
 *   · armar el archivo de pago   banco, cuenta, forma de pago
 *   · revisar cargas            número de asegurado, pensionado, exenciones
 *   · revisar salarios          salario base, valor del día, valor de la hora
 *
 * Entregar las cuarenta columnas para que después se borren a mano es lo que
 * hace que la gente termine manteniendo su propio Excel aparte, y ese Excel
 * es el que después no coincide con el sistema.
 *
 * ── Las columnas derivadas son la razón de ser del reporte ───────────────
 *
 * El equivalente mensual, el valor del día, el valor de la hora y la
 * antigüedad NO están en la tabla: se calculan con las reglas del módulo.
 * Que salgan acá —y no en una hoja aparte que alguien arma con fórmulas— es
 * lo que evita que circulen dos valores del día distintos para la misma
 * persona.
 */
class EmployeeRosterReport implements HasSelectableColumns, PayrollReport
{
    public function code(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Empleados';
    }

    public function description(): string
    {
        return 'La ficha de cada trabajador, con las columnas que se necesiten.';
    }

    public function decision(): string
    {
        return 'Quién está en planilla, con qué condiciones y con qué datos incompletos antes de calcular.';
    }

    public function group(): string
    {
        return 'Personal';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, 'active', options: [
                'active' => 'Activos',
                'suspended' => 'Suspendidos',
                'terminated' => 'Salidos',
            ], hint: 'En blanco trae a todos, incluidos los que ya salieron.'),
            new ReportFilter('department_id', 'Departamento', ReportFilter::SELECT, optionSource: 'departments'),
            new ReportFilter('cost_center_id', 'Centro de costo', ReportFilter::SELECT, optionSource: 'cost_centers'),
            new ReportFilter('job_position_id', 'Puesto', ReportFilter::SELECT, optionSource: 'job_positions'),
            new ReportFilter('salary_type', 'Modalidad de pago', ReportFilter::SELECT, options: [
                'mensual' => 'Mensual',
                'quincenal' => 'Quincenal',
                'semanal' => 'Semanal',
                'diario' => 'Diario',
                'hora' => 'Por hora',
            ]),
            new ReportFilter('hired_from', 'Ingresó desde', ReportFilter::DATE),
            new ReportFilter('hired_to', 'Ingresó hasta', ReportFilter::DATE),
            new ReportFilter('incomplete', 'Solo con datos incompletos', ReportFilter::BOOLEAN,
                hint: 'Sin salario, sin número de asegurado, sin centro de costo, o con transferencia sin cuenta.'),
        ];
    }

    /** @return ReportColumn[] */
    public function availableColumns(): array
    {
        return [
            new ReportColumn('code', 'Código'),
            new ReportColumn('full_name', 'Trabajador'),
            new ReportColumn('identification', 'Identificación'),
            new ReportColumn('identification_type', 'Tipo de identificación'),
            new ReportColumn('ccss_number', 'N.º asegurado'),
            new ReportColumn('birth_date', 'Nacimiento', ReportColumn::DATE),
            new ReportColumn('age', 'Edad', ReportColumn::NUMBER),
            new ReportColumn('gender', 'Género'),
            new ReportColumn('nationality', 'Nacionalidad'),
            new ReportColumn('email', 'Correo'),
            new ReportColumn('phone', 'Teléfono'),
            new ReportColumn('address', 'Dirección'),
            new ReportColumn('hire_date', 'Ingreso', ReportColumn::DATE),
            new ReportColumn('years_of_service', 'Antigüedad (años)', ReportColumn::NUMBER),
            new ReportColumn('termination_date', 'Salida', ReportColumn::DATE),
            new ReportColumn('termination_reason', 'Motivo de salida'),
            new ReportColumn('department', 'Departamento'),
            new ReportColumn('position', 'Puesto'),
            new ReportColumn('cost_center', 'Centro de costo'),
            new ReportColumn('contract_type', 'Contrato'),
            new ReportColumn('journey_type', 'Jornada'),
            new ReportColumn('weekly_hours', 'Horas semanales', ReportColumn::NUMBER),
            new ReportColumn('salary_type', 'Modalidad'),
            new ReportColumn('base_salary', 'Salario base', ReportColumn::MONEY, totalizable: true),
            // Derivadas: no están en la tabla, salen de las reglas del módulo.
            new ReportColumn('monthly_salary', 'Equivalente mensual', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('daily_rate', 'Valor del día', ReportColumn::MONEY),
            new ReportColumn('hourly_rate', 'Valor de la hora', ReportColumn::MONEY),
            new ReportColumn('weekly_salary_divisor', 'Divisor del día', ReportColumn::NUMBER),
            new ReportColumn('vacation_balance', 'Saldo de vacaciones', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('payment_method', 'Forma de pago'),
            new ReportColumn('bank_name', 'Banco'),
            new ReportColumn('bank_account', 'Cuenta'),
            new ReportColumn('has_spouse_credit', 'Crédito cónyuge'),
            new ReportColumn('children_credit_count', 'Hijos con crédito', ReportColumn::NUMBER),
            new ReportColumn('is_pensioner', 'Pensionado'),
            new ReportColumn('is_ccss_exempt', 'Exento de cargas'),
            new ReportColumn('is_income_tax_exempt', 'Exento de impuesto'),
            new ReportColumn('status', 'Estado'),
            new ReportColumn('missing', 'Datos que faltan'),
            new ReportColumn('notes', 'Notas'),
        ];
    }

    /** @return string[] */
    public function defaultColumns(): array
    {
        return [
            'code', 'full_name', 'identification', 'ccss_number', 'hire_date',
            'department', 'position', 'cost_center', 'salary_type', 'base_salary',
            'monthly_salary', 'daily_rate', 'status',
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $query = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with(['costCenter:id,code,name', 'departmentRecord:id,name', 'jobPosition:id,code,name'])
            ->orderBy('code');

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        foreach (['department_id', 'cost_center_id', 'job_position_id', 'salary_type'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        if (($filters['hired_from'] ?? null) !== null) {
            $query->where('hire_date', '>=', $filters['hired_from']);
        }

        if (($filters['hired_to'] ?? null) !== null) {
            $query->where('hire_date', '<=', $filters['hired_to']);
        }

        $rows = [];

        foreach ($query->get() as $employee) {
            $missing = $this->missingData($employee);

            if (($filters['incomplete'] ?? false) && $missing === []) {
                continue;
            }

            $rows[] = $this->row($employee, $missing);
        }

        $notes = [];

        if (($filters['incomplete'] ?? false)) {
            $notes[] = 'Solo se listan los trabajadores con algún dato incompleto. '.
                'Un salario en cero o un número de asegurado en blanco no hace fallar la planilla: '.
                'produce una boleta que se ve normal y está mal.';
        }

        return new ReportResult($this->availableColumns(), $rows, notes: $notes);
    }

    /**
     * @param  string[]  $missing
     * @return array<string, mixed>
     */
    private function row(Employee $employee, array $missing): array
    {
        return [
            'code' => $employee->code,
            'full_name' => $employee->fullName(),
            'identification' => $employee->identification_number,
            'identification_type' => $employee->identification_type,
            'ccss_number' => $employee->ccss_number,
            'birth_date' => $employee->birth_date?->format('Y-m-d'),
            'age' => $employee->birth_date === null ? null : (int) $employee->birth_date->diffInYears(now()),
            'gender' => $employee->gender,
            'nationality' => $employee->nationality,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'address' => $employee->address,
            'hire_date' => $employee->hire_date->format('Y-m-d'),
            'years_of_service' => $employee->yearsOfService(now()),
            'termination_date' => $employee->termination_date?->format('Y-m-d'),
            'termination_reason' => $employee->termination_reason,
            'department' => $employee->departmentLabel(),
            'position' => $employee->positionLabel(),
            'cost_center' => $employee->costCenter?->code,
            'contract_type' => $employee->contract_type,
            'journey_type' => $employee->journey_type,
            'weekly_hours' => (float) $employee->weekly_hours,
            'salary_type' => $employee->salary_type,
            'base_salary' => (float) $employee->base_salary,
            'monthly_salary' => (float) $employee->monthlySalary(),
            'daily_rate' => (float) $employee->dailyRate(),
            'hourly_rate' => (float) $employee->hourlyRate(),
            // Solo dice algo en el semanal; en las demás modalidades el
            // divisor lo fija la ley y mostrarlo confundiría.
            'weekly_salary_divisor' => $employee->salary_type === 'semanal'
                ? (int) ($employee->weekly_salary_divisor ?: Employee::DEFAULT_WEEKLY_DIVISOR)
                : null,
            'vacation_balance' => (float) $employee->vacationBalance(),
            'payment_method' => $employee->payment_method,
            'bank_name' => $employee->bank_name,
            'bank_account' => $employee->bank_account,
            'has_spouse_credit' => $employee->has_spouse_credit ? 'Sí' : 'No',
            'children_credit_count' => $employee->children_credit_count,
            'is_pensioner' => $employee->is_pensioner ? 'Sí' : 'No',
            'is_ccss_exempt' => $employee->is_ccss_exempt ? 'Sí' : 'No',
            'is_income_tax_exempt' => $employee->is_income_tax_exempt ? 'Sí' : 'No',
            'status' => $employee->status,
            'missing' => $missing === [] ? null : implode(', ', $missing),
            'notes' => $employee->notes,
        ];
    }

    /**
     * Qué le falta a una ficha para que la planilla salga bien.
     *
     * Es la misma lista que revisa PayrollReadinessChecker antes de calcular,
     * pero acá sirve para corregir ANTES de llegar a ese momento, sobre la
     * lista completa y no de a un hallazgo.
     *
     * @return string[]
     */
    private function missingData(Employee $employee): array
    {
        $missing = [];

        if (bccomp((string) $employee->base_salary, '0.00', 2) <= 0) {
            $missing[] = 'salario';
        }

        if ($employee->ccss_number === null || trim($employee->ccss_number) === '') {
            $missing[] = 'n.º asegurado';
        }

        if ($employee->cost_center_id === null) {
            $missing[] = 'centro de costo';
        }

        if ($employee->payment_method === 'transferencia'
            && ($employee->bank_account === null || trim($employee->bank_account) === '')) {
            $missing[] = 'cuenta bancaria';
        }

        return $missing;
    }
}
