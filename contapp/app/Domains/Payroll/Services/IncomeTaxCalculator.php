<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollTaxBracket;
use App\Domains\Payroll\Models\PayrollTaxCredit;

/**
 * Impuesto sobre la renta al trabajo dependiente ("impuesto al salario").
 *
 * ── La escala es PROGRESIVA y MENSUAL ────────────────────────────────────
 *
 * Cada tramo grava únicamente la porción del salario que cae dentro de él.
 * Aplicar la tasa del tramo superior a todo el salario —el error clásico—
 * cobra de más y produce un salto imposible al cruzar el límite: alguien que
 * gana mil colones más terminaría recibiendo menos neto.
 *
 * ── Por qué se calcula sobre el MES y no sobre la quincena ───────────────
 *
 * La escala está escrita en montos mensuales. Con planilla quincenal hay que
 * llevar la base al mes, aplicar la escala, y devolver la mitad — no aplicar
 * la escala a media base, que caería en un tramo más bajo y rebajaría de
 * menos. Es la diferencia entre un ajuste a fin de año y ninguno.
 *
 * ── Los créditos familiares se restan del IMPUESTO ───────────────────────
 *
 * No de la base. Restarlos de la base daría un alivio distinto y menor del
 * que la ley concede, y variable según el tramo del trabajador.
 *
 * ── Lo que este cálculo NO hace ──────────────────────────────────────────
 *
 * No acumula el año ni ajusta por diferencias de meses anteriores. El
 * impuesto al salario costarricense es de retención mensual sobre el salario
 * del mes, no un anticipo de una liquidación anual como en otros países. Si
 * una empresa quisiera un ajuste anual, es otro proceso y otra decisión.
 */
class IncomeTaxCalculator
{
    /**
     * @param  string  $monthlyBase  base gravable llevada a un mes
     * @param  string  $date  fecha rectora: fija qué escala rige
     * @return array{tax: string, credits: string, brackets: array<int, array<string, string>>}
     */
    public function calculate(
        Company $company,
        Employee $employee,
        string $monthlyBase,
        string $date,
    ): array {
        if ($employee->is_income_tax_exempt || bccomp($monthlyBase, '0.00', 2) <= 0) {
            return ['tax' => '0.00', 'credits' => '0.00', 'brackets' => []];
        }

        $brackets = PayrollTaxBracket::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->effectiveOn($date)
            ->orderBy('bracket_number')
            ->get();

        $tax = '0.00';
        $detail = [];

        foreach ($brackets as $bracket) {
            $from = (string) $bracket->from_amount;

            if (bccomp($monthlyBase, $from, 2) <= 0) {
                continue;
            }

            // Solo la porción que cae DENTRO del tramo: de ahí lo progresivo.
            $upper = $bracket->to_amount === null
                ? $monthlyBase
                : (bccomp($monthlyBase, (string) $bracket->to_amount, 2) < 0
                    ? $monthlyBase
                    : (string) $bracket->to_amount);

            $taxable = bcsub($upper, $from, 2);

            if (bccomp($taxable, '0.00', 2) <= 0) {
                continue;
            }

            $portion = bcdiv(bcmul($taxable, (string) $bracket->percentage, 4), '100', 2);
            $tax = bcadd($tax, $portion, 2);

            $detail[] = [
                'bracket' => (string) $bracket->bracket_number,
                'taxable' => $taxable,
                'rate' => (string) $bracket->percentage,
                'amount' => $portion,
            ];
        }

        $credits = $this->credits($company, $employee, $date);

        // El crédito no genera devolución: si supera el impuesto, este queda
        // en cero y el excedente se pierde. Permitir un neto negativo
        // convertiría una exención en un pago del patrono al trabajador.
        $final = bccomp($tax, $credits, 2) > 0 ? bcsub($tax, $credits, 2) : '0.00';

        return [
            'tax' => $final,
            'credits' => $credits,
            'brackets' => $detail,
        ];
    }

    /**
     * Los créditos familiares mensuales del trabajador: cónyuge e hijos.
     */
    private function credits(Company $company, Employee $employee, string $date): string
    {
        $rates = PayrollTaxCredit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->effectiveOn($date)
            ->get()
            ->keyBy('code');

        $total = '0.00';

        if ($employee->has_spouse_credit && $rates->has('spouse')) {
            $total = bcadd($total, (string) $rates['spouse']->monthly_amount, 2);
        }

        if ($employee->children_credit_count > 0 && $rates->has('child')) {
            $total = bcadd(
                $total,
                bcmul((string) $rates['child']->monthly_amount, (string) $employee->children_credit_count, 2),
                2
            );
        }

        return $total;
    }
}
