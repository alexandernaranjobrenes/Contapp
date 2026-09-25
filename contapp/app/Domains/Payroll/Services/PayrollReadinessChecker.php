<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PayrollTaxBracket;

/**
 * Revisa que la planilla se pueda correr ANTES de correrla.
 *
 * ── Por qué esto va antes y no después ───────────────────────────────────
 *
 * Una planilla mal configurada no falla con un error: produce números. Un
 * empleado sin salario sale con neto cero y nadie lo nota entre cincuenta
 * boletas. Una carga sin cuenta de pasivo no se ve hasta que alguien intenta
 * contabilizar, tres días después, con el cierre encima. Una escala de
 * impuesto con un hueco entre tramos deja parte del salario sin gravar y el
 * resultado se ve perfectamente razonable.
 *
 * Todos esos son problemas que se pueden encontrar leyendo la configuración,
 * sin calcular nada. Encontrarlos acá cuesta segundos; encontrarlos después
 * cuesta una planilla mal pagada.
 *
 * ── La diferencia entre error y advertencia no es de tono ────────────────
 *
 * Un ERROR produciría una planilla incorrecta: no se debe calcular así. Una
 * ADVERTENCIA produce una planilla correcta con una consecuencia aguas abajo
 * —un archivo de pago incompleto, un reporte de la Caja que se rechaza— y la
 * decisión de seguir es del usuario, no del sistema.
 *
 * Confundirlas en cualquiera de los dos sentidos es dañino: bloquear por una
 * advertencia enseña a la gente a ignorar los avisos; avisar de un error
 * hace que se calcule igual.
 */
class PayrollReadinessChecker
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @return array{ok: bool, errors: int, warnings: int, findings: array<int, array<string, mixed>>}
     */
    public function check(Company $company, PayrollPeriod $period): array
    {
        $date = $period->end_date->format('Y-m-d');

        $findings = [
            ...$this->checkConfiguration($company, $date),
            ...$this->checkTaxScale($company, $date),
            ...$this->checkEmployees($company, $period),
        ];

        $errors = collect($findings)->where('severity', self::ERROR)->count();

        return [
            // Se puede calcular mientras no haya errores. Las advertencias no
            // bloquean: la decisión de seguir con ellas es del usuario.
            'ok' => $errors === 0,
            'errors' => $errors,
            'warnings' => count($findings) - $errors,
            'findings' => $findings,
        ];
    }

    /**
     * Cuentas y parámetros de la compañía.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkConfiguration(Company $company, string $date): array
    {
        $findings = [];

        $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();

        $contributions = PayrollContribution::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->effectiveOn($date)->orderBy('code')->get();

        $provisions = PayrollProvision::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->effectiveOn($date)->orderBy('code')->get();

        if ($contributions->isEmpty()) {
            $findings[] = $this->finding(
                self::WARNING, 'configuracion', 'No hay cargas sociales vigentes',
                "Ninguna carga social rige al {$date}. La planilla va a calcular el bruto y el impuesto, ".
                'sin rebajar ni aportar cargas. Si es una compañía sin cargas, está bien; si no, revisá las vigencias.',
                'payroll-settings.index'
            );
        }

        // El gasto y el pasivo del neto son lo mínimo para armar el asiento.
        // Sin ellos la planilla calcula pero no se contabiliza, y eso se
        // descubre al final.
        if ($settings === null || $settings->salary_expense_account_id === null) {
            $findings[] = $this->finding(
                self::WARNING, 'cuentas', 'Falta la cuenta de gasto de salarios',
                'La planilla se puede calcular, pero no se va a poder contabilizar.',
                'payroll-settings.index'
            );
        }

        if ($settings === null || $settings->net_payable_account_id === null) {
            $findings[] = $this->finding(
                self::WARNING, 'cuentas', 'Falta la cuenta de planilla por pagar',
                'La planilla se puede calcular, pero no se va a poder contabilizar.',
                'payroll-settings.index'
            );
        }

        if ($settings !== null && $settings->document_type_id === null) {
            $findings[] = $this->finding(
                self::WARNING, 'cuentas', 'Falta el tipo de documento del asiento',
                'Sin tipo de documento no hay con qué contabilizar la planilla.',
                'payroll-settings.index'
            );
        }

        foreach ($contributions as $contribution) {
            if ($contribution->liability_account_id === null) {
                $findings[] = $this->finding(
                    self::WARNING, 'cuentas', "La carga {$contribution->code} no tiene cuenta de pasivo",
                    $contribution->payer === 'employee'
                        ? 'Lo retenido al trabajador va a caer en «planilla por pagar»: cuadra, pero mezcla la '.
                          'deuda con la institución y la deuda con el trabajador.'
                        : 'La planilla no se va a poder contabilizar.',
                    'payroll-settings.index'
                );
            }

            if ($contribution->payer === 'employer' && $contribution->expense_account_id === null) {
                $findings[] = $this->finding(
                    self::WARNING, 'cuentas', "La carga patronal {$contribution->code} no tiene cuenta de gasto",
                    'La planilla no se va a poder contabilizar.',
                    'payroll-settings.index'
                );
            }
        }

        foreach ($provisions as $provision) {
            if (bccomp((string) $provision->percentage, '0', 4) <= 0) {
                continue;
            }

            if ($provision->expense_account_id === null || $provision->liability_account_id === null) {
                $findings[] = $this->finding(
                    self::WARNING, 'cuentas', "La provisión {$provision->code} no tiene sus dos cuentas",
                    'La planilla no se va a poder contabilizar.',
                    'payroll-settings.index'
                );
            }
        }

        return $findings;
    }

    /**
     * La escala del impuesto.
     *
     * Un hueco entre tramos deja parte del salario sin gravar y un traslape lo
     * grava dos veces. Ninguno de los dos produce un error visible: producen
     * un impuesto equivocado que parece razonable.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkTaxScale(Company $company, string $date): array
    {
        $brackets = PayrollTaxBracket::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->effectiveOn($date)
            ->orderBy('bracket_number')
            ->get();

        if ($brackets->isEmpty()) {
            return [$this->finding(
                self::WARNING, 'impuesto', 'No hay escala de impuesto vigente',
                "Ningún tramo rige al {$date}: no se le va a rebajar impuesto a nadie.",
                'payroll-settings.index'
            )];
        }

        $findings = [];

        if (bccomp((string) $brackets->first()->from_amount, '0', 2) !== 0) {
            $findings[] = $this->finding(
                self::ERROR, 'impuesto', 'La escala no arranca en cero',
                "El primer tramo empieza en {$brackets->first()->from_amount}: los salarios por debajo ".
                'quedarían fuera de la escala.',
                'payroll-settings.index'
            );
        }

        if ($brackets->last()->to_amount !== null) {
            $findings[] = $this->finding(
                self::ERROR, 'impuesto', 'El último tramo tiene techo',
                "La escala termina en {$brackets->last()->to_amount}: un salario por encima de ese monto ".
                'no tributaría por el exceso.',
                'payroll-settings.index'
            );
        }

        $ordered = $brackets->values();

        for ($i = 0; $i < $ordered->count() - 1; $i++) {
            $top = $ordered[$i]->to_amount;

            if ($top === null) {
                continue;
            }

            $next = (string) $ordered[$i + 1]->from_amount;
            $comparison = bccomp((string) $top, $next, 2);

            if ($comparison !== 0) {
                $findings[] = $this->finding(
                    self::ERROR, 'impuesto',
                    $comparison < 0
                        ? "Hueco entre los tramos {$ordered[$i]->bracket_number} y {$ordered[$i + 1]->bracket_number}"
                        : "Traslape entre los tramos {$ordered[$i]->bracket_number} y {$ordered[$i + 1]->bracket_number}",
                    $comparison < 0
                        ? "El tramo termina en {$top} y el siguiente empieza en {$next}: esa porción del salario no se gravaría."
                        : "El tramo termina en {$top} y el siguiente empieza en {$next}: esa porción se gravaría dos veces.",
                    'payroll-settings.index'
                );
            }
        }

        return $findings;
    }

    /**
     * Los empleados que van a entrar en esta planilla.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkEmployees(Company $company, PayrollPeriod $period): array
    {
        $employees = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('code')
            ->get();

        $included = $employees->filter(fn (Employee $e) => $e->wasEmployedDuring(
            $period->start_date->format('Y-m-d'),
            $period->end_date->format('Y-m-d')
        ));

        if ($included->isEmpty()) {
            return [$this->finding(
                self::ERROR, 'empleados', 'Ningún empleado entra en este período',
                $employees->isEmpty()
                    ? 'No hay empleados activos registrados.'
                    : "Hay {$employees->count()} empleado(s) activo(s), pero ninguno estaba contratado entre ".
                      "{$period->start_date->format('Y-m-d')} y {$period->end_date->format('Y-m-d')}. ".
                      'Revisá las fechas del período y las de ingreso.',
                'employees.index'
            )];
        }

        $findings = [];

        // Los activos que quedan FUERA se avisan uno por uno: es el caso que
        // el usuario descubre cuando alguien reclama que no le pagaron.
        foreach ($employees->diff($included) as $employee) {
            $findings[] = $this->finding(
                self::WARNING, 'empleados', "{$employee->code} — {$employee->fullName()} queda fuera del período",
                "Está activo pero su relación laboral (ingreso {$employee->hire_date->format('Y-m-d')}".
                ($employee->termination_date ? ", salida {$employee->termination_date->format('Y-m-d')}" : '').
                ') no cubre ningún día de este período.',
                'employees.show', $employee->id
            );
        }

        foreach ($included as $employee) {
            $label = "{$employee->code} — {$employee->fullName()}";

            // Un salario en cero produce una boleta en cero que se pierde
            // entre las demás. Es error, no advertencia.
            if (bccomp((string) $employee->base_salary, '0.00', 2) <= 0) {
                $findings[] = $this->finding(
                    self::ERROR, 'empleados', "{$label} no tiene salario",
                    'Su boleta saldría en cero. Completá el salario base en su ficha.',
                    'employees.show', $employee->id
                );
            }

            if (bccomp((string) $employee->weekly_hours, '0.00', 2) <= 0) {
                $findings[] = $this->finding(
                    self::ERROR, 'empleados', "{$label} no tiene jornada semanal",
                    'Sin horas semanales no se puede derivar el valor de la hora, y las horas extra saldrían en cero.',
                    'employees.show', $employee->id
                );
            }

            if ($employee->cost_center_id === null) {
                $findings[] = $this->finding(
                    self::WARNING, 'empleados', "{$label} no tiene centro de costo",
                    'Su gasto va a quedar sin centro: no va a aparecer en el costo de ningún departamento.',
                    'employees.show', $employee->id
                );
            }

            // La cuenta bancaria solo importa si cobra por transferencia, y es
            // advertencia porque la planilla sale bien: lo que falla después
            // es el archivo de pago.
            if ($employee->payment_method === 'transferencia' && blank($employee->bank_account)) {
                $findings[] = $this->finding(
                    self::WARNING, 'empleados', "{$label} cobra por transferencia y no tiene cuenta",
                    'No va a entrar en el archivo de pago del banco y habrá que transferirle a mano.',
                    'employees.show', $employee->id
                );
            }

            if (! $employee->is_ccss_exempt && blank($employee->ccss_number)) {
                $findings[] = $this->finding(
                    self::WARNING, 'empleados', "{$label} no tiene número de asegurado",
                    'La planilla de la Caja lo va a rechazar por ese renglón.',
                    'employees.show', $employee->id
                );
            }
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    private function finding(
        string $severity,
        string $group,
        string $title,
        string $detail,
        string $routeName,
        ?int $routeParameter = null,
    ): array {
        return [
            'severity' => $severity,
            'group' => $group,
            'title' => $title,
            'detail' => $detail,
            // Cada hallazgo sabe a dónde hay que ir a arreglarlo: un aviso que
            // no dice dónde se corrige obliga a buscarlo.
            'route' => $routeName,
            'route_parameter' => $routeParameter,
        ];
    }
}
