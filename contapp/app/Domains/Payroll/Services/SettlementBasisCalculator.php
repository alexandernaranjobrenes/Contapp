<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Las bases de una liquidación: de dónde sale el valor del día con el que se
 * multiplica cada extremo.
 *
 * ── Es la parte que más se equivoca de una liquidación ───────────────────
 *
 * No se liquida con el salario de la ficha. Se liquida con el PROMEDIO de lo
 * realmente devengado, y el promedio casi siempre es mayor: incluye horas
 * extra, comisiones, bonificaciones y recargos. Usar el salario de la ficha
 * le paga de menos a cualquiera que haya hecho una hora extra en el semestre,
 * que es casi todo el mundo.
 *
 * ── Las ventanas ─────────────────────────────────────────────────────────
 *
 *   PREAVISO Y CESANTÍA   últimos 6 meses (CT art. 30)
 *   VACACIONES            depende del parámetro vacation_average_basis:
 *                           · práctica → 2 semanas si el pago es semanal,
 *                             y los mismos 6 meses si es quincenal o mensual
 *                           · literal del art. 157 → últimas 50 semanas
 *   AGUINALDO             del 1 de diciembre anterior a la salida, ÷ 12
 *                         (Ley 6949, no es un promedio sino una suma)
 *
 * La práctica de las vacaciones es la que indicó el usuario y es aceptada por
 * el Ministerio de Trabajo. Ver el encabezado de la migración del parámetro,
 * incluido el único caso en que perjudica al trabajador y por eso se avisa.
 *
 * ── El divisor no es 30 para todos ──────────────────────────────────────
 *
 * El promedio se convierte en valor del día dividiendo entre los días que el
 * historial PAGÓ, no entre 30 fijo. Para un trabajador semanal el mes paga 26
 * días y no 30 (ver Employee::paidDaysPerMonth); dividir entre 30 le quita un
 * 15% de cada día liquidado.
 *
 * ── Sin historial no se inventa, se avisa ───────────────────────────────
 *
 * Si la empresa acaba de empezar a usar el sistema, no hay planillas de las
 * cuales promediar. Ahí se cae al salario de la ficha y se devuelve
 * `from_history => false`: la pantalla lo muestra como advertencia y el
 * usuario decide. Calcular en silencio con la ficha es lo que produce
 * liquidaciones que pagan de menos sin que nadie se dé cuenta.
 */
class SettlementBasisCalculator
{
    /**
     * @return array{
     *     monthly: string,
     *     daily: string,
     *     months_found: int,
     *     from_history: bool,
     *     vacation_daily: string,
     *     vacation_window: string,
     *     vacation_legal_daily: string|null,
     *     vacation_shortfall: string|null,
     *     christmas_base: string,
     *     christmas_from_history: bool,
     *     christmas_window: string
     * }
     */
    public function for(
        Company $company,
        Employee $employee,
        string $terminationDate,
        ?PayrollSetting $settings,
    ): array {
        $end = Carbon::parse($terminationDate);

        // Todo el historial que cualquiera de las ventanas pueda necesitar se
        // trae de UNA sola consulta: la de 50 semanas es la más amplia, y
        // repetir la consulta por ventana multiplicaría el costo sin cambiar
        // el resultado.
        $history = $this->history($company, $employee, $end->copy()->subWeeks(50), $end);

        // ── Preaviso y cesantía: 6 meses (art. 30) ──────────────────────
        $sixMonths = $this->averageIn($history, $end->copy()->subMonths(6), $end, $employee);

        $fromHistory = $sixMonths !== null;

        $daily = $fromHistory ? $sixMonths['daily'] : $employee->dailyRate(2);
        $monthly = $fromHistory
            ? bcmul($daily, $employee->paidDaysPerMonth(), 2)
            : $employee->monthlySalary();

        // ── Vacaciones ──────────────────────────────────────────────────
        $basis = $settings?->vacation_average_basis ?? 'practice';

        [$vacationDaily, $vacationWindow] = $this->vacationBasis(
            $history, $employee, $end, $basis, $daily, $sixMonths
        );

        // El art. 157 es un mínimo irrenunciable. Si el criterio escogido
        // quedó por debajo, se calcula la diferencia para poder avisarla: no
        // se corrige sola, porque cuál criterio rige es decisión de la
        // empresa, pero tampoco puede quedar invisible.
        $legal = $basis === 'legal_50_weeks'
            ? null
            : $this->averageIn($history, $end->copy()->subWeeks(50), $end, $employee);

        $legalDaily = $legal['daily'] ?? null;

        $shortfall = $legalDaily !== null && bccomp($legalDaily, $vacationDaily, 2) > 0
            ? bcsub($legalDaily, $vacationDaily, 2)
            : null;

        // ── Aguinaldo: suma del 1 de diciembre a la salida ──────────────
        $christmas = $this->christmasBonusBase($company, $employee, $end);

        return [
            'monthly' => $monthly,
            'daily' => $daily,
            'months_found' => $sixMonths['months'] ?? 0,
            'from_history' => $fromHistory,
            'vacation_daily' => $vacationDaily,
            'vacation_window' => $vacationWindow,
            'vacation_legal_daily' => $legalDaily,
            'vacation_shortfall' => $shortfall,
            'christmas_base' => $christmas['base'],
            'christmas_from_history' => $christmas['from_history'],
            'christmas_window' => $christmas['window'],
        ];
    }

    /**
     * El valor del día de las vacaciones y el texto que explica de dónde
     * salió. Ese texto va a la liquidación impresa: es lo que le permite al
     * trabajador entender el número sin tener que preguntarlo.
     *
     * @param  array{daily: string, months: int}|null  $sixMonths
     * @return array{0: string, 1: string}
     */
    private function vacationBasis(
        Collection $history,
        Employee $employee,
        Carbon $end,
        string $basis,
        string $sixMonthDaily,
        ?array $sixMonths,
    ): array {
        if ($basis === 'legal_50_weeks') {
            $legal = $this->averageIn($history, $end->copy()->subWeeks(50), $end, $employee);

            return $legal === null
                ? [$employee->dailyRate(2), 'salario de la ficha: no hay planillas de las últimas 50 semanas']
                : [$legal['daily'], 'promedio de las últimas 50 semanas (CT art. 157)'];
        }

        // Práctica. El pago semanal usa las últimas dos semanas; los demás
        // comparten la ventana del preaviso y la cesantía, que es lo que la
        // vuelve una sola base verificable.
        if ($employee->salary_type !== 'semanal') {
            return $sixMonths === null
                ? [$employee->dailyRate(2), 'salario de la ficha: no hay planillas de los últimos 6 meses']
                : [$sixMonthDaily, 'promedio de los últimos 6 meses, la misma base del preaviso y la cesantía'];
        }

        $twoWeeks = $this->averageIn($history, $end->copy()->subWeeks(2), $end, $employee);

        return $twoWeeks === null
            ? [$employee->dailyRate(2), 'salario de la ficha: no hay planillas de las últimas 2 semanas']
            : [$twoWeeks['daily'], 'promedio diario de las últimas 2 semanas (pago semanal)'];
    }

    /**
     * Boletas del trabajador en una ventana, con su período al lado.
     *
     * Solo períodos ya calculados: uno abierto todavía no devengó nada, y uno
     * anulado dejó de existir para todo efecto.
     *
     * @return Collection<int, array{entry: PayrollEntry, period: PayrollPeriod}>
     */
    private function history(Company $company, Employee $employee, Carbon $from, Carbon $to): Collection
    {
        $periods = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->whereDate('end_date', '<=', $to->format('Y-m-d'))
            ->get()
            ->keyBy('id');

        if ($periods->isEmpty()) {
            return collect();
        }

        return PayrollEntry::whereIn('payroll_period_id', $periods->keys())
            ->where('employee_id', $employee->id)
            ->get()
            ->map(fn (PayrollEntry $entry) => [
                'entry' => $entry,
                'period' => $periods[$entry->payroll_period_id],
            ])
            ->values();
    }

    /**
     * Promedio del día en una ventana: lo devengado entre los días que ese
     * devengo pagó.
     *
     * ── Por qué se divide entre días y no entre meses ───────────────────
     *
     * Porque así la fórmula no depende de la frecuencia de pago ni de que las
     * ventanas estén completas. Un trabajador con tres meses de historial se
     * promedia sobre sus tres meses, no sobre seis: dividir entre seis le
     * partiría el promedio a la mitad y le pagaría la liquidación con la
     * mitad de su salario.
     *
     * @param  Collection<int, array{entry: PayrollEntry, period: PayrollPeriod}>  $history
     * @return array{daily: string, months: int}|null
     */
    private function averageIn(Collection $history, Carbon $from, Carbon $to, Employee $employee): ?array
    {
        $earnings = '0.00';
        $days = '0.0000';
        $months = [];

        foreach ($history as $row) {
            $endDate = $row['period']->end_date;

            if ($endDate->lt($from) || $endDate->gt($to)) {
                continue;
            }

            // Se suma la base de cargas, no el total de ingresos: lo que la
            // ley promedia son los SALARIOS, ordinarios y extraordinarios
            // (art. 30). Los viáticos y los reembolsos no son salario —son
            // devolución de un gasto— y meterlos en el promedio infla la
            // liquidación tanto como excluir las horas extra la desinfla.
            $earnings = bcadd($earnings, (string) $row['entry']->ccss_base, 2);
            $days = bcadd($days, $this->daysPaid($row['entry'], $row['period'], $employee), 4);
            $months[$endDate->format('Y-m')] = true;
        }

        if (bccomp($days, '0', 4) <= 0 || bccomp($earnings, '0.00', 2) <= 0) {
            return null;
        }

        return [
            'daily' => bcdiv($earnings, $days, 2),
            'months' => count($months),
        ];
    }

    /**
     * Días que pagó una boleta.
     *
     * `days_worked` ya trae la convención de 30 y 15 días de la planilla
     * mensual y quincenal, con la proporción de quien entró o salió a mitad
     * de período. Lo que no trae es el divisor del semanal: cuenta los 7 días
     * calendario de la semana, y la ficha puede decir que la semana paga 6
     * (ver Employee::dailyRate). Se escala para que el promedio salga con el
     * mismo divisor con el que se paga todo lo demás — si no, un mismo
     * trabajador tendría un valor del día distinto en la planilla y en la
     * liquidación.
     */
    private function daysPaid(PayrollEntry $entry, PayrollPeriod $period, Employee $employee): string
    {
        $days = (string) $entry->days_worked;

        if ($period->frequency !== 'semanal') {
            return $days;
        }

        $divisor = (string) ($employee->weekly_salary_divisor ?: Employee::DEFAULT_WEEKLY_DIVISOR);

        return bcdiv(bcmul($days, $divisor, 4), '7', 4);
    }

    /**
     * Base del aguinaldo: los salarios del 1 de diciembre anterior a la
     * salida hasta la salida (Ley 6949).
     *
     * No es un promedio: es una SUMA que después se divide entre 12. Y el
     * divisor es siempre 12, aunque se hayan trabajado tres meses — de ahí
     * sale lo «proporcional».
     *
     * @return array{base: string, from_history: bool, window: string}
     */
    private function christmasBonusBase(Company $company, Employee $employee, Carbon $end): array
    {
        // El 1 de diciembre anterior: si la salida es en diciembre, es el de
        // ese mismo año; si no, el del año pasado.
        $from = $end->month === 12
            ? Carbon::create($end->year, 12, 1)
            : Carbon::create($end->year - 1, 12, 1);

        // Nunca antes de la fecha de ingreso: a quien entró en marzo no se le
        // busca historial de diciembre.
        if ($employee->hire_date->gt($from)) {
            $from = $employee->hire_date->copy();
        }

        $window = "del {$from->format('d/m/Y')} al {$end->format('d/m/Y')}";

        $history = $this->history($company, $employee, $from, $end);

        $earned = $history->reduce(
            fn (string $carry, array $row) => bcadd($carry, (string) $row['entry']->ccss_base, 2),
            '0.00'
        );

        if (bccomp($earned, '0.00', 2) > 0) {
            return ['base' => $earned, 'from_history' => true, 'window' => $window];
        }

        // Sin historial se estima con el salario de la ficha y los meses
        // transcurridos. Queda marcado para que la pantalla lo advierta.
        //
        // Se mensualiza y se prorratea por meses calendario en vez de
        // multiplicar el valor del día por los días: el aguinaldo cubre meses
        // completos, y un trabajador semanal —cuyo mes paga 26 días y no 30—
        // saldría con un 15% de más por el camino del día.
        $months = bcdiv((string) ($from->diffInDays($end) + 1), '30', 6);

        return [
            'base' => bcmul($employee->monthlySalary(), $months, 2),
            'from_history' => false,
            'window' => $window.' (estimado con el salario de la ficha)',
        ];
    }
}
