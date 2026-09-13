<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\IncomeStatementLine;
use App\Domains\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Collection;

/**
 * Roll-up jerárquico por prefijo de código de cuenta (no por parent_id/level,
 * que existen en el modelo pero nunca se pueblan en ningún flujo real — ver
 * docs/decisiones.md 2026-08-27, y confirmado contra catálogos reales de
 * clientes). Una cuenta "mayor" (accepts_posting=false, ej. "1-01-01" CAJA Y
 * BANCOS) nunca recibe asientos directos, pero sí debe aparecer en los
 * reportes financieros mostrando la suma de todo lo que cuelga bajo su
 * código — sus hijas hoja Y sus hijas mayores intermedias, a cualquier
 * profundidad.
 *
 * Compartido entre BalanceSheetService e IncomeStatementService (antes cada
 * uno tenía su propio buildSection() casi idéntico, ninguno de los dos
 * incluía las cuentas mayores).
 */
class AccountRollupBuilder
{
    /**
     * @param  Collection<int, ChartOfAccount>  $accounts  cuentas de UN account_type, ya ordenadas por code (orderBy('code') en el query del caller) — ese orden es el que produce padre-antes-que-hijos al renderizar.
     * @param  Collection<int, string>  $ownAmounts  account_id => monto propio ya firmado según normal_balance (positivo si mueve a favor de esa cuenta), calculado por el caller desde journal_details. Una cuenta mayor nunca tiene movimiento propio real, pero la fórmula no necesita distinguirlo: si no aparece en el mapa, se asume '0.00'.
     * @param  bool  $hideZero  oculta del resultado cualquier línea (hoja o mayor) cuyo total con roll-up dé exactamente cero.
     * @return array{0: IncomeStatementLine[], 1: string} líneas en orden de despliegue + total de la sección (SOLO cuentas hoja — sumar también las mayores duplicaría, ya que su monto es la suma de sus propias hojas).
     */
    public function build(Collection $accounts, Collection $ownAmounts, bool $hideZero): array
    {
        $total = '0.00';
        foreach ($accounts->where('accepts_posting', true) as $leaf) {
            $total = bcadd($total, $ownAmounts->get($leaf->id, '0.00'), 2);
        }

        $rolledAmounts = $this->sumByAccount($accounts, $ownAmounts);

        $lines = [];
        foreach ($accounts as $account) {
            $rolled = $rolledAmounts->get($account->id, '0.00');

            if ($hideZero && $rolled === '0.00') {
                continue;
            }

            $lines[] = new IncomeStatementLine(
                code: $account->code,
                description: $account->description_es,
                amount: $rolled,
                depth: substr_count($account->code, '-'),
                isHeader: ! $account->accepts_posting,
            );
        }

        return [$lines, $total];
    }

    /**
     * Versión reutilizable de la misma suma por prefijo de código, para
     * reportes cuya línea necesita más de un monto por cuenta (ej. Balance
     * de comprobación: saldo inicial, débito y crédito del período por
     * separado — no encaja en el IncomeStatementLine de un solo `amount` que
     * usa build()). El caller la llama una vez por cada columna que necesite
     * enrollar.
     *
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @param  Collection<int, string>  $ownValues  account_id => monto propio
     * @return Collection<int, string> account_id => self + descendientes
     */
    public function sumByAccount(Collection $accounts, Collection $ownValues): Collection
    {
        return $accounts->mapWithKeys(function (ChartOfAccount $account) use ($accounts, $ownValues) {
            $sum = '0.00';

            foreach ($accounts as $candidate) {
                if ($candidate->id === $account->id || str_starts_with($candidate->code, $account->code.'-')) {
                    $sum = bcadd($sum, $ownValues->get($candidate->id, '0.00'), 2);
                }
            }

            return [$account->id => $sum];
        });
    }
}
