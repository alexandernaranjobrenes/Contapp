<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\LaborSettlementLine;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El motor de la liquidación laboral.
 *
 * ── Qué se paga lo decide la CAUSAL, no el usuario ───────────────────────
 *
 * El aguinaldo y las vacaciones son irrenunciables: se pagan en toda causal,
 * hasta en un despido con justa causa. El preaviso y la cesantía dependen de
 * quién y por qué terminó la relación. Esa tabla está en
 * LaborSettlement::ENTITLEMENTS y sale del Código de Trabajo, no de una
 * casilla de la pantalla: dejarla abierta es invitar a que alguien liquide un
 * despido con responsabilidad sin cesantía y lo firme.
 *
 * ── El orden del cálculo ─────────────────────────────────────────────────
 *
 *   1. Bases          promedios del historial (SettlementBasisCalculator)
 *   2. Días           la escala del art. 28 y la del art. 29; las vacaciones
 *                     salen del saldo acumulado sin disfrutar
 *   3. Extremos       un renglón por cada uno que la causal permita
 *   4. Cargas         SOLO sobre lo que tiene naturaleza salarial
 *   5. Impuesto       sobre esa misma parte, acumulando el mes de la salida
 *   6. Deducciones    saldos de préstamo y lo que se agregue a mano
 *   7. Neto
 *
 * ── Las cargas no van sobre todo, y es lo que más se equivoca ────────────
 *
 * Solo los salarios pendientes y las vacaciones cotizan. La cesantía y el
 * preaviso son indemnizatorios y el aguinaldo tiene exención expresa.
 * Rebajarle el 10,83% a la cesantía le quita al trabajador plata que la ley
 * no permite rebajar; y no rebajárselo a las vacaciones deja a la empresa
 * debiéndole a la Caja.
 *
 * ── Recalcular borra y rehace ────────────────────────────────────────────
 *
 * Igual que la planilla, y por la misma razón: una liquidación recalculada
 * tiene que ser idéntica a una calculada desde cero. Solo se puede mientras
 * está en borrador; después hay una firma o un asiento detrás.
 */
class CalculateSettlementService
{
    public function __construct(
        private readonly SettlementBasisCalculator $bases,
        private readonly IncomeTaxCalculator $incomeTax,
    ) {}

    /**
     * @param  array<int, array{kind: string, code?: string, name: string, days?: string|null, daily_rate?: string|null, amount?: string|null, detail?: string|null}>  $extraLines
     *                                                                                                                                                                              renglones que el usuario agrega a mano: salarios pendientes,
     *                                                                                                                                                                              deducciones autorizadas, indemnizaciones pactadas
     */
    public function calculate(Company $company, LaborSettlement $settlement, array $extraLines = []): LaborSettlement
    {
        if (! $settlement->isRecalculable()) {
            throw new InvalidPayrollException(
                'La liquidación está '.mb_strtolower(LaborSettlement::STATUSES[$settlement->status] ?? $settlement->status).
                ' y ya no se puede recalcular.'
            );
        }

        $employee = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOrFail($settlement->employee_id);

        $date = $settlement->termination_date->format('Y-m-d');

        if ($employee->hire_date->gt($settlement->termination_date)) {
            throw new InvalidPayrollException(
                'La fecha de salida es anterior a la de ingreso: no hay relación laboral que liquidar.'
            );
        }

        return DB::transaction(function () use ($company, $settlement, $employee, $date, $extraLines) {
            $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)->first();

            $bases = $this->bases->for($company, $employee, $date, $settings);

            $settlement->lines()->delete();

            $lines = $this->buildLines($company, $settlement, $employee, $bases, $extraLines);

            // ── 4 y 5. Cargas e impuesto, solo sobre la parte salarial ───
            $salaryBase = $this->sumWhere(
                $lines,
                fn (array $l) => in_array($l['kind'], LaborSettlementLine::SALARY_NATURE_KINDS, true)
            );

            $ccss = $this->employeeContributions($company, $employee, $date, $salaryBase);
            $tax = $this->incomeTaxOn($company, $employee, $settlement, $settings, $date, $salaryBase, $ccss);

            $gross = $this->sumWhere($lines, fn (array $l) => $l['kind'] !== 'deduction');
            $otherDeductions = $this->sumWhere($lines, fn (array $l) => $l['kind'] === 'deduction');

            $this->persistLines($settlement, $lines);

            $settlement->fill([
                'average_monthly_salary' => $bases['monthly'],
                'average_daily_salary' => $bases['daily'],
                'vacation_daily_salary' => $bases['vacation_daily'],
                'christmas_bonus_base' => $bases['christmas_base'],
                'years_of_service' => $this->serviceYears($employee, $settlement->termination_date),
                'bases_from_history' => $bases['from_history'],
                'history_months_found' => $bases['months_found'],
                'total_gross' => $gross,
                'total_ccss' => $ccss,
                'total_income_tax' => $tax,
                'total_other_deductions' => $otherDeductions,
                'total_net' => bcsub($gross, bcadd(bcadd($ccss, $tax, 2), $otherDeductions, 2), 2),
                'calculated_at' => now(),
            ])->save();

            return $settlement->fresh(['lines']);
        });
    }

    /**
     * Las advertencias de una liquidación ya calculada.
     *
     * No bloquean: informan. Una liquidación se firma y se archiva, así que lo
     * que no se pueda comprobar tiene que quedar dicho ANTES de la firma, no
     * descubrirse en una demanda.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function findings(Company $company, LaborSettlement $settlement): array
    {
        $employee = $settlement->employee ?? Employee::withoutGlobalScope(CompanyScope::class)
            ->findOrFail($settlement->employee_id);

        $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();

        $bases = $this->bases->for(
            $company, $employee, $settlement->termination_date->format('Y-m-d'), $settings
        );

        $findings = [];

        if (! $bases['from_history']) {
            $findings[] = [
                'level' => 'warning',
                'message' => 'No hay planillas calculadas de los últimos 6 meses para este trabajador: '.
                    'el preaviso y la cesantía se están calculando con el salario de la ficha, no con el '.
                    'promedio de lo devengado. Si tuvo horas extra o comisiones, la liquidación le está '.
                    'pagando de menos.',
            ];
        } elseif ($bases['months_found'] < 6) {
            $findings[] = [
                'level' => 'warning',
                'message' => "El promedio se calculó con {$bases['months_found']} mes(es) de historial en vez de 6. ".
                    'Es correcto para quien tiene poca antigüedad; si no es el caso, faltan planillas por cargar.',
            ];
        }

        if (! $bases['christmas_from_history']) {
            $findings[] = [
                'level' => 'warning',
                'message' => 'El aguinaldo se estimó con el salario de la ficha: no hay planillas calculadas '.
                    'desde el 1 de diciembre anterior a la salida.',
            ];
        }

        // El caso en que la práctica de vacaciones queda por debajo del
        // mínimo del art. 157. Ver el encabezado de la migración del
        // parámetro: pasa cuando el trabajador tuvo extras hace más de seis
        // meses y ninguna después.
        if ($bases['vacation_shortfall'] !== null) {
            $findings[] = [
                'level' => 'warning',
                'message' => 'El promedio de vacaciones escogido (₡'.$bases['vacation_daily'].' el día) queda '.
                    '₡'.$bases['vacation_shortfall'].' por debajo del promedio de las últimas 50 semanas '.
                    '(₡'.$bases['vacation_legal_daily'].'), que es el mínimo del art. 157. En este caso la '.
                    'práctica sí perjudica al trabajador: conviene pagar con el criterio legal, que se cambia '.
                    'en Configuración → Parámetros.',
            ];
        }

        if ($employee->vacationMovements()->count() === 0) {
            $findings[] = [
                'level' => 'warning',
                'message' => 'El trabajador no tiene movimientos de vacaciones registrados, así que el saldo '.
                    'a liquidar es cero. Si acumuló días fuera del sistema, hay que cargarlos antes de aprobar.',
            ];
        }

        $entitlements = $settlement->entitlements();

        if ($settlement->reason === 'renuncia') {
            $findings[] = [
                'level' => 'info',
                'message' => 'En una renuncia el preaviso lo debe el TRABAJADOR (CT art. 28). No se le paga, y '.
                    'rebajárselo requiere su autorización expresa: si la hay, se agrega como deducción.',
            ];
        }

        if ($entitlements['indemnity'] ?? false) {
            $findings[] = [
                'level' => 'info',
                'message' => 'Esta causal admite una indemnización adicional que no se calcula sola porque '.
                    'depende del caso (art. 31, daños y perjuicios, o lo pactado). Se agrega a mano.',
            ];
        }

        return $findings;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $bases
     * @param  array<int, array<string, mixed>>  $extraLines
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(
        Company $company,
        LaborSettlement $settlement,
        Employee $employee,
        array $bases,
        array $extraLines,
    ): array {
        $entitlements = $settlement->entitlements();
        $accounts = $this->accounts($company, $settlement->termination_date->format('Y-m-d'));

        $lines = [];

        // ── Salarios pendientes: los digita el usuario ───────────────────
        //
        // No se derivan solos a propósito. Los días que quedaron sin pagar
        // dependen de hasta cuándo llegó la última planilla, y adivinarlo
        // produciría un doble pago silencioso el día que la planilla del mes
        // ya incluyó la fracción.
        foreach ($extraLines as $extra) {
            $line = $this->extraLine($extra, $employee, $bases, $accounts);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        // ── Aguinaldo proporcional ──────────────────────────────────────
        if ($entitlements['christmas_bonus'] ?? false) {
            $amount = $this->round(bcdiv($bases['christmas_base'], '12', 6));

            if (bccomp($amount, '0.00', 2) > 0) {
                $lines[] = [
                    'kind' => 'christmas_bonus',
                    'code' => 'AGUINALDO',
                    'name' => 'Aguinaldo proporcional',
                    'detail' => 'salarios '.$bases['christmas_window'].' (₡'.$bases['christmas_base'].') ÷ 12',
                    'days' => null,
                    'daily_rate' => null,
                    'amount' => $amount,
                    'subject_to_ccss' => false,
                    'subject_to_income_tax' => false,
                    'account_id' => $accounts['aguinaldo'],
                ];
            }
        }

        // ── Vacaciones: el saldo acumulado sin disfrutar ─────────────────
        //
        // Los días NO se calculan con una fórmula de proporcionalidad: se
        // toman del saldo que el control de vacaciones lleva acumulado y que
        // el trabajador no usó. Fue la decisión del usuario, y tiene una
        // consecuencia que conviene tener presente: todo el peso queda en la
        // tasa de acumulación mensual del parámetro, porque es ella la que
        // construyó ese saldo mes a mes.
        if ($entitlements['vacation'] ?? false) {
            $days = $employee->vacationBalance();

            if (bccomp($days, '0.0000', 4) > 0) {
                $lines[] = [
                    'kind' => 'vacation',
                    'code' => 'VACACIONES',
                    'name' => 'Vacaciones no disfrutadas',
                    'detail' => $days.' día(s) de saldo acumulado × ₡'.$bases['vacation_daily'].
                        ' — '.$bases['vacation_window'],
                    'days' => $days,
                    'daily_rate' => $bases['vacation_daily'],
                    'amount' => $this->round(bcmul($days, $bases['vacation_daily'], 6)),
                    // Las vacaciones SÍ son salario: cotizan y tributan.
                    'subject_to_ccss' => true,
                    'subject_to_income_tax' => true,
                    'account_id' => $accounts['vacaciones'],
                ];
            }
        }

        [$completedYears, $remainingMonths, $totalMonths] = $this->tenure(
            $employee, $settlement->termination_date
        );

        // ── Preaviso (art. 28) ──────────────────────────────────────────
        if ($entitlements['notice'] ?? false) {
            $notice = LaborSettlement::noticeDaysFor($totalMonths);

            if (bccomp($notice['days'], '0.0000', 4) > 0) {
                $lines[] = [
                    'kind' => 'notice',
                    'code' => 'PREAVISO',
                    'name' => 'Preaviso',
                    'detail' => $notice['detail'].': '.rtrim(rtrim($notice['days'], '0'), '.').
                        ' días × ₡'.$bases['daily'],
                    'days' => $notice['days'],
                    'daily_rate' => $bases['daily'],
                    'amount' => $this->round(bcmul($notice['days'], $bases['daily'], 6)),
                    // Indemnizatorio: no cotiza ni tributa.
                    'subject_to_ccss' => false,
                    'subject_to_income_tax' => false,
                    'account_id' => $accounts['preaviso'],
                ];
            }
        }

        // ── Cesantía (art. 29) ──────────────────────────────────────────
        if ($entitlements['severance'] ?? false) {
            $severance = LaborSettlement::severanceDaysFor($completedYears, $remainingMonths);

            if (bccomp($severance['days'], '0.0000', 4) > 0) {
                $lines[] = [
                    'kind' => 'severance',
                    'code' => 'CESANTIA',
                    'name' => 'Auxilio de cesantía',
                    'detail' => $severance['detail'].': '.rtrim(rtrim($severance['days'], '0'), '.').
                        ' días × ₡'.$bases['daily'],
                    'days' => $severance['days'],
                    'daily_rate' => $bases['daily'],
                    'amount' => $this->round(bcmul($severance['days'], $bases['daily'], 6)),
                    'subject_to_ccss' => false,
                    'subject_to_income_tax' => false,
                    'account_id' => $accounts['cesantia'],
                ];
            }
        }

        return $this->sorted($lines);
    }

    /**
     * Un renglón agregado a mano. Se acepta por monto o por días × tarifa; si
     * vienen los días sin tarifa, se usa la base que corresponda al extremo.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $bases
     * @param  array<string, int|null>  $accounts
     * @return array<string, mixed>|null
     */
    private function extraLine(array $extra, Employee $employee, array $bases, array $accounts): ?array
    {
        $kind = $extra['kind'] ?? 'deduction';

        if (! array_key_exists($kind, LaborSettlementLine::KINDS)) {
            throw new InvalidPayrollException("El tipo de renglón «{$kind}» no existe en una liquidación.");
        }

        $days = isset($extra['days']) && $extra['days'] !== null && $extra['days'] !== ''
            ? (string) $extra['days']
            : null;

        $rate = isset($extra['daily_rate']) && $extra['daily_rate'] !== null && $extra['daily_rate'] !== ''
            ? (string) $extra['daily_rate']
            : ($days !== null ? $bases['daily'] : null);

        $amount = isset($extra['amount']) && $extra['amount'] !== null && $extra['amount'] !== ''
            ? $this->round((string) $extra['amount'])
            : ($days !== null && $rate !== null ? $this->round(bcmul($days, $rate, 6)) : '0.00');

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $salaryNature = in_array($kind, LaborSettlementLine::SALARY_NATURE_KINDS, true);

        return [
            'kind' => $kind,
            'code' => (string) ($extra['code'] ?? mb_strtoupper($kind)),
            'name' => (string) ($extra['name'] ?? LaborSettlementLine::KINDS[$kind]),
            'detail' => $extra['detail'] ?? null,
            'days' => $days,
            'daily_rate' => $rate,
            'amount' => $amount,
            'subject_to_ccss' => $salaryNature,
            'subject_to_income_tax' => $salaryNature,
            'account_id' => $accounts[$kind] ?? $accounts['salary'],
        ];
    }

    /**
     * Las cuentas contra las que se registra cada extremo.
     *
     * ── Los extremos provisionados debitan su PASIVO, no un gasto ───────
     *
     * El aguinaldo, las vacaciones y la cesantía se fueron provisionando mes a
     * mes: el gasto ya se reconoció y quedó un pasivo. Pagarlos vuelve a
     * cargarlo a gasto solo si se ignora la provisión, y entonces la empresa
     * registra el mismo gasto dos veces y deja el pasivo inflado para siempre.
     *
     * El preaviso es el caso distinto: su provisión viene al 0% porque solo
     * existe cuando hay despido sin aviso. Ese sí es gasto del período en que
     * ocurre.
     *
     * @return array<string, int|null>
     */
    private function accounts(Company $company, string $date): array
    {
        $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();

        $provisions = PayrollProvision::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->effectiveOn($date)
            ->get()
            ->keyBy('code');

        $salary = $settings?->salary_expense_account_id;

        $provisioned = fn (string $code) => $provisions[$code]->liability_account_id
            ?? $provisions[$code]->expense_account_id
            ?? $salary;

        return [
            'aguinaldo' => $provisions->has('aguinaldo') ? $provisioned('aguinaldo') : $salary,
            'vacaciones' => $provisions->has('vacaciones') ? $provisioned('vacaciones') : $salary,
            'cesantia' => $provisions->has('cesantia') ? $provisioned('cesantia') : $salary,
            // El preaviso al gasto del período: su provisión está en 0%.
            'preaviso' => $provisions['preaviso']->expense_account_id ?? $salary,
            'salary' => $salary,
            'pending_salary' => $salary,
            'indemnity' => $salary,
            'deduction' => $settings?->net_payable_account_id,
        ];
    }

    /**
     * Cargas obreras sobre la parte salarial de la liquidación.
     *
     * Se leen de la configuración con su vigencia a la fecha de salida, igual
     * que en la planilla: una liquidación de marzo se reproduce con las tasas
     * de marzo. Y respeta lo del pensionado —no cotiza IVM— por la misma
     * bandera de siempre.
     */
    private function employeeContributions(
        Company $company,
        Employee $employee,
        string $date,
        string $base,
    ): string {
        if ($employee->is_ccss_exempt || bccomp($base, '0.00', 2) <= 0) {
            return '0.00';
        }

        $contributions = PayrollContribution::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('payer', 'employee')
            ->effectiveOn($date)
            ->get();

        $total = '0.00';

        foreach ($contributions as $contribution) {
            if ($employee->is_pensioner && $contribution->exempt_for_pensioner) {
                continue;
            }

            $ceiling = $contribution->ceiling_amount;

            $applicable = $ceiling !== null && bccomp($base, (string) $ceiling, 2) > 0
                ? (string) $ceiling
                : $base;

            $total = bcadd(
                $total,
                $this->round(bcdiv(bcmul($applicable, (string) $contribution->percentage, 6), '100', 6)),
                2
            );
        }

        return $total;
    }

    /**
     * Impuesto al salario sobre la parte salarial de la liquidación.
     *
     * ── Se acumula con el mes de la salida ──────────────────────────────
     *
     * La escala es mensual y progresiva, así que lo que ya se le retuvo a este
     * trabajador en el mes en que sale forma parte de la misma base. Calcular
     * la liquidación como si fuera un mes aparte la haría caer en tramos bajos
     * y retendría de menos.
     *
     * La consulta no es la misma que la de la planilla —esa filtra por
     * frecuencia y por fecha de inicio, porque compara períodos entre sí— acá
     * simplemente se suma todo lo retenido en el mes: la liquidación es, por
     * definición, lo último que se le paga.
     */
    private function incomeTaxOn(
        Company $company,
        Employee $employee,
        LaborSettlement $settlement,
        ?PayrollSetting $settings,
        string $date,
        string $salaryBase,
        string $contributions,
    ): string {
        if (bccomp($salaryBase, '0.00', 2) <= 0) {
            return '0.00';
        }

        // Igual que en la planilla: si la configuración dice que la base va
        // neta de cargas obreras, se restan antes de aplicar la escala.
        $base = ($settings?->income_tax_base ?? 'gross') === 'net_of_contributions'
            ? bcsub($salaryBase, $contributions, 2)
            : $salaryBase;

        [$priorBase, $priorTax] = $this->priorWithholdingThisMonth($company, $employee, $settlement);

        $tax = $this->incomeTax->calculate($company, $employee, bcadd($priorBase, $base, 2), $date);

        $due = bcsub($tax['tax'], $priorTax, 2);

        return bccomp($due, '0.00', 2) > 0 ? $due : '0.00';
    }

    /**
     * Base e impuesto ya retenidos a este trabajador en el mes de la salida.
     *
     * @return array{0: string, 1: string}
     */
    private function priorWithholdingThisMonth(
        Company $company,
        Employee $employee,
        LaborSettlement $settlement,
    ): array {
        $month = $settlement->termination_date;

        $periodIds = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->whereDate('end_date', '>=', $month->copy()->startOfMonth()->format('Y-m-d'))
            ->whereDate('end_date', '<=', $month->copy()->endOfMonth()->format('Y-m-d'))
            ->pluck('id');

        if ($periodIds->isEmpty()) {
            return ['0.00', '0.00'];
        }

        $entries = PayrollEntry::whereIn('payroll_period_id', $periodIds)
            ->where('employee_id', $employee->id)
            ->get(['income_tax_base', 'income_tax']);

        return [
            $entries->reduce(fn ($c, PayrollEntry $e) => bcadd($c, (string) $e->income_tax_base, 2), '0.00'),
            $entries->reduce(fn ($c, PayrollEntry $e) => bcadd($c, (string) $e->income_tax, 2), '0.00'),
        ];
    }

    /**
     * Antigüedad en años completos, meses sobrantes y meses totales.
     *
     * Los tres se necesitan: el preaviso mira los meses totales (art. 28) y
     * la cesantía los años completos más la fracción (art. 29).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function tenure(Employee $employee, Carbon $terminationDate): array
    {
        $hire = $employee->hire_date->copy();

        $totalMonths = (int) $hire->diffInMonths($terminationDate);
        $completedYears = intdiv($totalMonths, 12);

        return [$completedYears, $totalMonths % 12, $totalMonths];
    }

    private function serviceYears(Employee $employee, Carbon $terminationDate): string
    {
        $months = (string) (int) $employee->hire_date->copy()->diffInMonths($terminationDate);

        return bcdiv($months, '12', 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function sorted(array $lines): array
    {
        $order = array_keys(LaborSettlementLine::KINDS);

        usort($lines, fn (array $a, array $b) => array_search($a['kind'], $order, true)
            <=> array_search($b['kind'], $order, true));

        return $lines;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function persistLines(LaborSettlement $settlement, array $lines): void
    {
        $number = 0;

        foreach ($lines as $line) {
            $settlement->lines()->create($line + ['line_number' => ++$number]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $filter
     */
    private function sumWhere(array $lines, callable $filter): string
    {
        return collect($lines)
            ->filter($filter)
            ->reduce(fn (string $carry, array $line) => bcadd($carry, $line['amount'], 2), '0.00');
    }

    /**
     * Redondeo a céntimos, medio hacia arriba. El mismo criterio del motor de
     * planilla: dos redondeos distintos en el mismo sistema producen
     * diferencias de un céntimo que nadie sabe explicar.
     */
    private function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? mb_substr($value, 1) : $value;

        $rounded = bcadd($absolute, '0.005', 2);

        return $negative ? '-'.$rounded : $rounded;
    }
}
