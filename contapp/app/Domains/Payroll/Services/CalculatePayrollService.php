<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\DataTransferObjects\PayrollInputLine;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\EmployeeDeductionApplication;
use App\Domains\Payroll\Models\EmployeeRecurringInput;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\VacationMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El motor de cálculo de la planilla.
 *
 * ── El orden del cálculo NO es negociable ────────────────────────────────
 *
 *   1. Ingresos            salario del período + conceptos de ingreso
 *   2. Base de cargas      solo los ingresos que forman salario
 *   3. Cargas obreras      sobre esa base, componente por componente
 *   4. Base del impuesto   bruto gravable MENOS las cargas obreras
 *   5. Impuesto            escala mensual progresiva, menos créditos
 *   6. Otras deducciones   préstamos, adelantos, cuota solidarista
 *   7. Neto                ingresos − (cargas + impuesto + deducciones)
 *   8. Costo patronal      cargas patronales y provisiones, aparte
 *
 * El paso 4 es el que más se equivoca: las cargas sociales obreras se restan
 * ANTES de aplicar la escala del impuesto. Calcular el impuesto sobre el
 * bruto cobra de más a todos los trabajadores, todos los meses.
 *
 * ── Recalcular BORRA y rehace ────────────────────────────────────────────
 *
 * Y es deliberado: una planilla recalculada tiene que ser exactamente lo que
 * produciría un cálculo desde cero. Intentar "actualizar" las líneas
 * existentes deja rastros de conceptos que ya no aplican, que es como
 * aparecen los rebajos fantasma.
 *
 * Pero borrar tiene una consecuencia que no es obvia: los rebajos de
 * préstamo ya bajaron el saldo de la obligación. Si se borra la boleta sin
 * DEVOLVER ese saldo, el siguiente cálculo vuelve a rebajar sobre un saldo
 * ya disminuido y el trabajador termina pagando su préstamo dos veces. Por
 * eso restoreBalances() corre antes del borrado, siempre.
 *
 * Solo se puede recalcular mientras el período está abierto o calculado:
 * después hay un asiento que lo respalda.
 */
class CalculatePayrollService
{
    public function __construct(private readonly IncomeTaxCalculator $incomeTax) {}

    /**
     * @param  PayrollInputLine[]  $inputs  movimientos digitados del período
     */
    public function calculate(Company $company, PayrollPeriod $period, array $inputs = []): PayrollPeriod
    {
        if (! $period->isRecalculable()) {
            throw new InvalidPayrollException(
                "El período {$period->name} está ".mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                ' y ya no se puede recalcular: hay un asiento que lo respalda.'
            );
        }

        return DB::transaction(function () use ($company, $period, $inputs) {
            // La fecha rectora de TODA la planilla es el fin del período: es
            // la que decide qué tasas y qué escala rigen. Usar la fecha de
            // hoy haría que recalcular una planilla vieja la cambiara.
            $date = $period->end_date->format('Y-m-d');

            $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)->first();

            $contributions = PayrollContribution::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)->effectiveOn($date)->orderBy('code')->get();

            $provisions = PayrollProvision::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)->effectiveOn($date)->orderBy('code')->get();

            $concepts = PayrollConcept::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)->where('status', 'active')->get()->keyBy('id');

            $employees = Employee::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('status', ['active', 'suspended'])
                ->orderBy('code')
                ->get()
                ->filter(fn (Employee $e) => $e->wasEmployedDuring(
                    $period->start_date->format('Y-m-d'),
                    $period->end_date->format('Y-m-d')
                ));

            if ($employees->isEmpty()) {
                throw new InvalidPayrollException(
                    'No hay empleados activos que hayan estado contratados durante el período.'
                );
            }

            // Los rubros fijos se leen acá y no los pasa el llamador: así un
            // rubro fijo y uno digitado producen exactamente la misma línea y
            // no hay dos caminos de cálculo que puedan divergir.
            $recurring = EmployeeRecurringInput::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->effectiveOn($date)
                ->orderBy('employee_id')
                ->orderBy('id')
                ->get();

            $byEmployee = collect($this->mergeInputs($inputs, $recurring))->groupBy('employeeId');

            // Se descarta el cálculo anterior por completo —saldos de
            // préstamo devueltos y acreditaciones de vacaciones quitadas—
            // antes de rehacerlo. Ver discardCalculation().
            $this->discardCalculation($period);

            // Y las obligaciones se leen DESPUÉS de devolverlo. Leerlas antes
            // funciona la primera vez y falla al recalcular: la colección en
            // memoria conservaría el saldo ya rebajado y el segundo cálculo
            // volvería a restarle encima, cobrándole al trabajador dos veces
            // la misma cuota aunque la base de datos estuviera correcta.
            $deductions = EmployeeDeduction::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->effectiveOn($date)
                ->with('concept')
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');

            foreach ($employees as $employee) {
                $this->calculateEmployee(
                    $company, $period, $employee, $date, $settings,
                    $byEmployee->get($employee->id, collect())->all(),
                    $concepts, $contributions, $provisions,
                    $deductions->get($employee->id, collect())
                );
            }

            $period->update(['status' => 'calculated', 'calculated_at' => now()]);

            return $period->fresh();
        });
    }

    /**
     * El impuesto que le toca a ESTE período.
     *
     * ── El problema: la escala es mensual y el período puede no serlo ────
     *
     * Hay dos formas de llegar al mes, y la diferencia entre ellas es dinero
     * en la boleta de la gente.
     *
     *   ACUMULADO (por defecto)
     *     Se suma lo devengado de los períodos anteriores del MISMO MES y se
     *     le agrega el de este. Se aplica la escala a ese total y se retiene
     *     la diferencia contra lo que ya se retuvo antes.
     *
     *     Con pago mensual y adelantos quincenales, la primera quincena casi
     *     nunca llega al tramo exento y no retiene nada; en la segunda, la
     *     suma de las dos sí llega y se retiene todo el impuesto del mes de
     *     una vez. No hace falta que nadie marque nada: sale solo de la
     *     aritmética.
     *
     *   PROYECTADO
     *     Se multiplica la base de la quincena por dos, se aplica la escala
     *     y se retiene la mitad. Supone que la segunda quincena será igual a
     *     la primera, y con comisiones concentradas en una sola —que es lo
     *     normal— retiene de más en una y de menos en la otra.
     *
     * ── Por qué «anteriores» y no «todos los del mes» ────────────────────
     *
     * Solo cuentan los períodos que EMPIEZAN antes que este. Si se recalcula
     * la primera quincena cuando la segunda ya está hecha, tomar a la segunda
     * como «anterior» le restaría a la primera un impuesto que todavía no se
     * había retenido cuando ella se pagó.
     */
    private function incomeTaxFor(
        Company $company,
        Employee $employee,
        PayrollPeriod $period,
        ?PayrollSetting $settings,
        string $date,
        string $periodBase,
    ): string {
        if (($settings?->income_tax_mode ?? 'accumulated') === 'projected') {
            $perMonth = $this->periodsPerMonth($period);

            $tax = $this->incomeTax->calculate(
                $company, $employee, bcmul($periodBase, $perMonth, 2), $date
            );

            return bcdiv($tax['tax'], $perMonth, 2);
        }

        [$priorBase, $priorTax] = $this->priorWithholdingThisMonth($company, $employee, $period);

        $monthBase = bcadd($priorBase, $periodBase, 2);

        $tax = $this->incomeTax->calculate($company, $employee, $monthBase, $date);

        $due = bcsub($tax['tax'], $priorTax, 2);

        // Nunca negativo: si en un período posterior se devengó menos y el
        // impuesto del mes bajó, no se le devuelve plata al trabajador por
        // planilla — queda en cero y se arregla donde corresponda.
        return bccomp($due, '0.00', 2) > 0 ? $due : '0.00';
    }

    /**
     * Base gravable y impuesto ya retenidos a este trabajador en los períodos
     * anteriores del mismo mes.
     *
     * @return array{0: string, 1: string}
     */
    private function priorWithholdingThisMonth(Company $company, Employee $employee, PayrollPeriod $period): array
    {
        // El mes lo fija la fecha de FIN, igual que las vigencias de tasas.
        $monthStart = $period->end_date->copy()->startOfMonth()->format('Y-m-d');
        $monthEnd = $period->end_date->copy()->endOfMonth()->format('Y-m-d');

        $priorPeriodIds = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('frequency', $period->frequency)
            ->whereKeyNot($period->id)
            ->whereDate('end_date', '>=', $monthStart)
            ->whereDate('end_date', '<=', $monthEnd)
            ->whereDate('start_date', '<', $period->start_date->format('Y-m-d'))
            // Solo los que ya se calcularon: uno abierto todavía no retuvo
            // nada.
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->pluck('id');

        if ($priorPeriodIds->isEmpty()) {
            return ['0.00', '0.00'];
        }

        $entries = PayrollEntry::whereIn('payroll_period_id', $priorPeriodIds)
            ->where('employee_id', $employee->id)
            ->get(['income_tax_base', 'income_tax']);

        return [
            $entries->reduce(fn ($c, PayrollEntry $e) => bcadd($c, (string) $e->income_tax_base, 2), '0.00'),
            $entries->reduce(fn ($c, PayrollEntry $e) => bcadd($c, (string) $e->income_tax, 2), '0.00'),
        ];
    }

    /**
     * Junta los movimientos digitados del período con los rubros fijos.
     *
     * ── Lo digitado REEMPLAZA al rubro fijo, no se le suma ───────────────
     *
     * Es la decisión que hay que tomar explícita, porque las dos son
     * defendibles y una de las dos paga doble.
     *
     * Si alguien tiene una bonificación fija de ₡25.000 y este mes se le
     * digita «bonificación ₡40.000», lo que quiso decir es que este mes fue
     * de ₡40.000 — no que se le paguen ₡65.000. Sumarlas convierte una
     * corrección en un pago doble, y el número resultante no aparece en
     * ninguna parte como algo que alguien haya decidido.
     *
     * El reemplazo es por empleado y concepto, no por empleado: digitarle
     * horas extra a alguien no le quita su bonificación fija.
     *
     * Y opera solo dentro del período: el rubro fijo sigue vigente y vuelve a
     * aplicarse el período siguiente, sin que nadie lo reactive.
     *
     * @param  PayrollInputLine[]  $digitados
     * @param  Collection<int, EmployeeRecurringInput>  $recurring
     * @return PayrollInputLine[]
     */
    private function mergeInputs(array $digitados, $recurring): array
    {
        $overridden = collect($digitados)
            ->map(fn (PayrollInputLine $line) => "{$line->employeeId}:{$line->conceptId}")
            ->unique()
            ->flip();

        $merged = $digitados;

        foreach ($recurring as $fixed) {
            if ($overridden->has("{$fixed->employee_id}:{$fixed->payroll_concept_id}")) {
                continue;
            }

            $merged[] = $fixed->toInputLine();
        }

        return $merged;
    }

    /**
     * Deshace TODO lo que un cálculo movió fuera de la planilla.
     *
     * El cálculo no solo escribe boletas: rebaja el saldo de los préstamos y
     * acredita días de vacaciones. Descartar un período —al recalcularlo o al
     * borrarlo— tiene que deshacer las tres cosas, o el trabajador queda
     * debiendo menos de lo que debe y con días que nadie le acreditó, sin
     * ningún documento que lo explique.
     *
     * Es público porque el borrado del período lo necesita igual que el
     * recálculo: los dos descartan un cálculo, y hacerlo distinto en cada
     * lugar es como se separan dos copias de la misma regla.
     */
    public function discardCalculation(PayrollPeriod $period): void
    {
        $this->restoreBalances($period);

        PayrollEntry::where('payroll_period_id', $period->id)->delete();

        // Solo las acreditaciones automáticas: un ajuste o un disfrute los
        // digitó una persona y no son consecuencia de este cálculo.
        VacationMovement::withoutGlobalScope(CompanyScope::class)
            ->where('payroll_period_id', $period->id)
            ->where('type', 'accrual')
            ->delete();
    }

    /**
     * Devuelve a cada obligación el saldo que las boletas de este período le
     * habían rebajado, para que el recálculo parta del mismo estado que el
     * cálculo original.
     */
    private function restoreBalances(PayrollPeriod $period): void
    {
        $entryIds = PayrollEntry::where('payroll_period_id', $period->id)->pluck('id');

        if ($entryIds->isEmpty()) {
            return;
        }

        $applications = EmployeeDeductionApplication::whereIn('payroll_entry_id', $entryIds)->get();

        foreach ($applications->groupBy('employee_deduction_id') as $deductionId => $rows) {
            $deduction = EmployeeDeduction::withoutGlobalScope(CompanyScope::class)->find($deductionId);

            if ($deduction === null || ! $deduction->settles()) {
                continue;
            }

            $restored = $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->amount, 2), '0.00');

            $deduction->update([
                'balance' => bcadd((string) $deduction->balance, $restored, 2),
                // Una obligación dada por cancelada vuelve a estar viva si se
                // le devuelve saldo: dejarla en "settled" la sacaría
                // silenciosamente del siguiente cálculo.
                'status' => $deduction->status === 'settled' ? 'active' : $deduction->status,
            ]);
        }

        EmployeeDeductionApplication::whereIn('payroll_entry_id', $entryIds)->delete();
    }

    /**
     * @param  PayrollInputLine[]  $inputs
     */
    private function calculateEmployee(
        Company $company,
        PayrollPeriod $period,
        Employee $employee,
        string $date,
        ?PayrollSetting $settings,
        array $inputs,
        $concepts,
        $contributions,
        $provisions,
        $deductions,
    ): void {
        $daysWorked = $this->daysWorked($period, $employee);
        $periodSalary = $this->periodSalary($period, $employee, $daysWorked);

        $entry = PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            // Congelado: si el trabajador se traslada después, la planilla
            // vieja tiene que seguir cargando al centro que soportó el gasto.
            'cost_center_id' => $employee->cost_center_id,
            'days_worked' => $daysWorked,
            'base_salary' => $employee->base_salary,
            'payment_method' => $employee->payment_method,
            'bank_account' => $employee->bank_account,
        ]);

        $lines = [];

        // ── 1. Ingresos ─────────────────────────────────────────────────
        $lines[] = [
            'kind' => 'earning', 'code' => 'SALARIO', 'name' => 'Salario ordinario',
            'quantity' => $daysWorked, 'amount' => $periodSalary,
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'account_id' => null, 'concept_id' => null,
        ];

        foreach ($inputs as $input) {
            $concept = $concepts->get($input->conceptId);

            if ($concept === null) {
                throw new InvalidPayrollException(
                    "El concepto id {$input->conceptId} no existe o está inactivo en esta compañía."
                );
            }

            $amount = $this->conceptAmount($concept, $employee, $input);

            if (bccomp($amount, '0.00', 2) === 0) {
                continue;
            }

            $lines[] = [
                'kind' => $concept->type === 'earning' ? 'earning' : 'deduction',
                'code' => $concept->code,
                'name' => $concept->name,
                'quantity' => $input->quantity !== null ? (string) $input->quantity : null,
                'amount' => $amount,
                'affects_ccss' => (bool) $concept->affects_ccss,
                'affects_income_tax' => (bool) $concept->affects_income_tax,
                'affects_provisions' => (bool) $concept->affects_provisions,
                'account_id' => $concept->account_id,
                'concept_id' => $concept->id,
            ];
        }

        $totalEarnings = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'earning');
        $ccssBase = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'earning' && $l['affects_ccss']);
        $taxableEarnings = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'earning' && $l['affects_income_tax']);
        $provisionBase = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'earning' && $l['affects_provisions']);

        // ── 2 y 3. Cargas sociales obreras ──────────────────────────────
        $employeeContributions = '0.00';
        $computed = [];

        if (! $employee->is_ccss_exempt) {
            foreach ($contributions as $contribution) {
                $base = $this->contributionBase($contribution, $ccssBase, $totalEarnings);
                $amount = $this->percentageOf($base, (string) $contribution->percentage);

                if (bccomp($amount, '0.00', 2) === 0) {
                    continue;
                }

                $kind = $contribution->payer === 'employee' ? 'employee_contribution' : 'employer_contribution';

                $computed[] = [
                    'kind' => $kind,
                    'code' => $contribution->code,
                    'name' => $contribution->name,
                    'base_amount' => $base,
                    'rate' => (string) $contribution->percentage,
                    'amount' => $amount,
                    'account_id' => $contribution->payer === 'employee'
                        ? $contribution->liability_account_id
                        : $contribution->expense_account_id,
                    'contribution_id' => $contribution->id,
                ];

                if ($kind === 'employee_contribution') {
                    $employeeContributions = bcadd($employeeContributions, $amount, 2);
                }
            }
        }

        // ── 4 y 5. Impuesto al salario ──────────────────────────────────
        //
        // La base sale de la configuración: el devengado gravable, o ese
        // mismo menos las cargas obreras. Cuál de los dos rige es una
        // cuestión de la Ley del Impuesto sobre la Renta, no de este motor,
        // y por eso es un parámetro y no una decisión escrita acá.
        $taxableNet = ($settings?->income_tax_base ?? 'gross') === 'net_of_contributions'
            ? bcsub($taxableEarnings, $employeeContributions, 2)
            : $taxableEarnings;

        $incomeTax = $this->incomeTaxFor($company, $employee, $period, $settings, $date, $taxableNet);

        if (bccomp($incomeTax, '0.00', 2) > 0) {
            $computed[] = [
                'kind' => 'income_tax',
                'code' => 'IMP-SALARIO',
                'name' => 'Impuesto sobre la renta al salario',
                'base_amount' => $taxableNet,
                'rate' => null,
                'amount' => $incomeTax,
                'account_id' => $settings?->income_tax_payable_account_id,
                'contribution_id' => null,
            ];
        }

        // ── 6. Obligaciones recurrentes ─────────────────────────────────
        $digited = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'deduction');

        // Lo que queda del salario una vez hechos los rebajos que no son
        // optativos. Es sobre esto que se mide cuánto más se le puede
        // rebajar sin dejarlo sin salario.
        $available = bcsub(bcsub(bcsub($totalEarnings, $employeeContributions, 2), $incomeTax, 2), $digited, 2);

        foreach ($this->applyDeductions($deductions, $period, $entry, $settings, $totalEarnings, $available) as $line) {
            $lines[] = $line;
        }

        // ── 8. Provisiones ──────────────────────────────────────────────
        $totalProvisions = '0.00';

        foreach ($provisions as $provision) {
            $amount = $this->percentageOf($provisionBase, (string) $provision->percentage);

            if (bccomp($amount, '0.00', 2) === 0) {
                continue;
            }

            $computed[] = [
                'kind' => 'provision',
                'code' => $provision->code,
                'name' => $provision->name,
                'base_amount' => $provisionBase,
                'rate' => (string) $provision->percentage,
                'amount' => $amount,
                'account_id' => $provision->expense_account_id,
                'provision_id' => $provision->id,
            ];

            $totalProvisions = bcadd($totalProvisions, $amount, 2);
        }

        // ── Persistir ───────────────────────────────────────────────────
        $number = 1;

        foreach ($lines as $line) {
            PayrollEntryLine::create([
                'payroll_entry_id' => $entry->id,
                'line_number' => $number++,
                'kind' => $line['kind'],
                'code' => $line['code'],
                'name' => $line['name'],
                'payroll_concept_id' => $line['concept_id'],
                'quantity' => $line['quantity'],
                'amount' => $line['amount'],
                'account_id' => $line['account_id'],
            ]);
        }

        foreach ($computed as $line) {
            PayrollEntryLine::create([
                'payroll_entry_id' => $entry->id,
                'line_number' => $number++,
                'kind' => $line['kind'],
                'code' => $line['code'],
                'name' => $line['name'],
                'payroll_contribution_id' => $line['contribution_id'] ?? null,
                'payroll_provision_id' => $line['provision_id'] ?? null,
                'base_amount' => $line['base_amount'],
                'rate' => $line['rate'],
                'amount' => $line['amount'],
                'account_id' => $line['account_id'],
            ]);
        }

        $otherDeductions = $this->sumWhere($lines, fn ($l) => $l['kind'] === 'deduction');
        $employerContributions = collect($computed)
            ->where('kind', 'employer_contribution')
            ->reduce(fn ($carry, $l) => bcadd($carry, $l['amount'], 2), '0.00');

        $totalDeductions = bcadd(bcadd($employeeContributions, $incomeTax, 2), $otherDeductions, 2);

        $entry->update([
            'total_earnings' => $totalEarnings,
            'ccss_base' => $ccssBase,
            'income_tax_base' => $taxableNet,
            'total_employee_contributions' => $employeeContributions,
            'income_tax' => $incomeTax,
            'total_other_deductions' => $otherDeductions,
            'total_deductions' => $totalDeductions,
            'net_pay' => bcsub($totalEarnings, $totalDeductions, 2),
            'total_employer_contributions' => $employerContributions,
            'total_provisions' => $totalProvisions,
        ]);

        $this->accrueVacation($company, $period, $employee, $entry, $settings, $daysWorked);
    }

    /**
     * Aplica las obligaciones del trabajador en orden de prioridad, sin pasar
     * del tope.
     *
     * ── Por qué se detiene en vez de rebajar igual ───────────────────────
     *
     * Cuando el salario no alcanza para todos los rebajos, la alternativa a
     * detenerse es dejar al trabajador con un neto de cero o negativo. Una
     * planilla que hace eso no está "cuadrada": está cobrando por encima de
     * lo que el salario soporta. Lo que no cabe queda como saldo vivo y se
     * rebaja el período siguiente, que es exactamente lo que habría pasado
     * si el préstamo se hubiera pactado con cuotas más pequeñas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyDeductions(
        $deductions,
        PayrollPeriod $period,
        PayrollEntry $entry,
        ?PayrollSetting $settings,
        string $periodGross,
        string $available,
    ): array {
        if ($deductions->isEmpty() || bccomp($available, '0.00', 2) <= 0) {
            return [];
        }

        $maxPercentage = (string) ($settings->max_deduction_percentage ?? '0');

        // Sin tope configurado el límite sigue siendo el salario mismo: el
        // neto nunca puede quedar negativo.
        $room = bccomp($maxPercentage, '0', 2) > 0
            ? $this->percentageOf($available, $maxPercentage)
            : $available;

        $lines = [];

        foreach ($deductions as $deduction) {
            if (bccomp($room, '0.00', 2) <= 0) {
                break;
            }

            $amount = $deduction->installmentFor($periodGross);

            // Lo que no cabe se recorta, no se descarta: media cuota hoy es
            // mejor para el trabajador y para el acreedor que ninguna.
            if (bccomp($amount, $room, 2) > 0) {
                $amount = $room;
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            $balanceAfter = $deduction->settles()
                ? bcsub((string) $deduction->balance, $amount, 2)
                : null;

            EmployeeDeductionApplication::create([
                'employee_deduction_id' => $deduction->id,
                'payroll_entry_id' => $entry->id,
                'applied_on' => $period->end_date->format('Y-m-d'),
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ]);

            if ($deduction->settles()) {
                $deduction->update([
                    'balance' => $balanceAfter,
                    // Se extingue sola al llegar a cero: dejarla activa con
                    // saldo cero la mostraría para siempre en la lista de
                    // rebajos vivos de alguien que ya no debe nada.
                    'status' => bccomp($balanceAfter, '0.00', 2) <= 0 ? 'settled' : $deduction->status,
                ]);
            }

            $lines[] = [
                'kind' => 'deduction',
                'code' => $deduction->concept?->code ?? mb_strtoupper($deduction->type),
                'name' => $deduction->description,
                'quantity' => null,
                'amount' => $amount,
                'affects_ccss' => false,
                'affects_income_tax' => false,
                'affects_provisions' => false,
                'account_id' => $deduction->account_id ?? $deduction->concept?->account_id,
                'concept_id' => $deduction->payroll_concept_id,
            ];

            $room = bcsub($room, $amount, 2);
        }

        return $lines;
    }

    /**
     * Acredita las vacaciones que el trabajador ganó en este período.
     *
     * Proporcional a los días efectivamente cubiertos: quien entró a mitad de
     * quincena no ganó la quincena completa de vacaciones.
     */
    private function accrueVacation(
        Company $company,
        PayrollPeriod $period,
        Employee $employee,
        PayrollEntry $entry,
        ?PayrollSetting $settings,
        string $daysWorked,
    ): void {
        $perMonth = (string) ($settings->vacation_days_per_month ?? '1');

        if (bccomp($perMonth, '0', 4) <= 0) {
            return;
        }

        // Los días cubiertos sobre 30 dan la fracción de mes; por los días
        // por mes, lo acreditado. Usar la fracción y no un divisor fijo por
        // frecuencia hace que una quincena de 15 días y otra de 16 acrediten
        // distinto, que es lo correcto.
        $days = bcdiv(bcmul($daysWorked, $perMonth, 6), '30', 4);

        if (bccomp($days, '0.0000', 4) <= 0) {
            return;
        }

        VacationMovement::create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'type' => 'accrual',
            'movement_date' => $period->end_date->format('Y-m-d'),
            'days' => $days,
            'payroll_period_id' => $period->id,
            'payroll_entry_id' => $entry->id,
            'notes' => "Acreditación automática del período {$period->name}",
        ]);
    }

    /**
     * Días del período que el trabajador estuvo contratado.
     *
     * Quien ingresa o sale a mitad de período NO se excluye ni cobra
     * completo: cobra lo proporcional. Excluirlo dejaría sin pagar días
     * efectivamente trabajados.
     */
    private function daysWorked(PayrollPeriod $period, Employee $employee): string
    {
        $start = max($period->start_date->format('Y-m-d'), $employee->hire_date->format('Y-m-d'));

        $end = $employee->termination_date !== null
            ? min($period->end_date->format('Y-m-d'), $employee->termination_date->format('Y-m-d'))
            : $period->end_date->format('Y-m-d');

        if ($start > $end) {
            return '0.00';
        }

        // Con mes convencional de 30 días, quien ingresa el 20 de agosto cobra
        // 11 días de la segunda quincena (del 20 al 30) y quien sale el 20
        // cobra 5 (del 16 al 20); quien sale el 31 cobra los 15 de la
        // quincena, porque el mes de planilla no tiene día 31.
        $days = $period->usesConventionalMonth()
            ? PayrollPeriod::conventionalDay(Carbon::parse($end))
                - PayrollPeriod::conventionalDay(Carbon::parse($start)) + 1
            : Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

        return number_format(max($days, 0), 2, '.', '');
    }

    /**
     * El salario que corresponde al período, proporcional a los días.
     */
    private function periodSalary(PayrollPeriod $period, Employee $employee, string $daysWorked): string
    {
        $fullPeriodSalary = bcdiv($employee->monthlySalary(), $this->periodsPerMonth($period), 2);
        $periodDays = (string) $period->dayCount();

        if (bccomp($daysWorked, $periodDays, 2) >= 0) {
            return $fullPeriodSalary;
        }

        return $this->round(bcdiv(bcmul($fullPeriodSalary, $daysWorked, 6), $periodDays, 6));
    }

    /**
     * Cuántos períodos de esta frecuencia caben en un mes. Es el factor que
     * lleva la base al mes para la escala del impuesto y de vuelta.
     */
    private function periodsPerMonth(PayrollPeriod $period): string
    {
        return match ($period->frequency) {
            'quincenal' => '2',
            'semanal' => '4.3333',
            default => '1',
        };
    }

    private function contributionBase(PayrollContribution $contribution, string $ccssBase, string $gross): string
    {
        $base = $contribution->base === 'gross' ? $gross : $ccssBase;

        // Un tope salarial acota la BASE, no el resultado: aplicarlo al
        // monto ya calculado daría un número distinto.
        if ($contribution->ceiling_amount !== null
            && bccomp($base, (string) $contribution->ceiling_amount, 2) > 0) {
            return (string) $contribution->ceiling_amount;
        }

        return $base;
    }

    private function conceptAmount(PayrollConcept $concept, Employee $employee, PayrollInputLine $input): string
    {
        return match ($concept->calculation) {
            // Horas extra: cantidad × valor hora ordinaria × factor. El
            // factor lo declara el concepto (1,5 para extra simple, 2 para
            // doble) y no se asume acá.
            //
            // El valor de la hora se pide con cuatro decimales: es un factor
            // intermedio, y truncarlo a céntimos antes de multiplicar pierde
            // una fracción en cada hora, siempre en contra del trabajador.
            'hours' => $this->round(bcmul(
                bcmul(
                    number_format((float) ($input->quantity ?? 0), 4, '.', ''),
                    $employee->hourlyRate(6),
                    6
                ),
                (string) ($concept->factor ?? 1),
                6
            )),
            'percentage' => $this->percentageOf($employee->monthlySalary(), (string) ($concept->factor ?? 0)),
            default => number_format((float) ($input->amount ?? 0), 2, '.', ''),
        };
    }

    private function percentageOf(string $base, string $percentage): string
    {
        return $this->round(bcdiv(bcmul($base, $percentage, 8), '100', 6));
    }

    /**
     * Redondeo al céntimo, medio hacia arriba.
     *
     * bcmath TRUNCA, y truncar el monto de cada línea siempre hacia abajo es
     * un sesgo sistemático en contra del trabajador: no es un céntimo, es un
     * céntimo por línea, por persona, por período. El redondeo va acá, al
     * final, sobre el monto que de verdad se paga — los pasos intermedios
     * siguen calculándose con más decimales.
     *
     * Se contempla el negativo porque un rubro de devengado puede restar
     * (horas de incapacidad, por ejemplo) y ahí «medio hacia arriba» tiene
     * que alejarse del cero igual que del lado positivo.
     */
    private function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 4) < 0
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    private function sumWhere(array $lines, callable $filter): string
    {
        return collect($lines)
            ->filter($filter)
            ->reduce(fn ($carry, $line) => bcadd($carry, $line['amount'], 2), '0.00');
    }
}
