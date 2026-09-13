<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\CostAllocationRuleLine;
use Illuminate\Support\Collection;

/**
 * Reparte un monto (string decimal, 2 posiciones) entre las líneas de una
 * norma de reparto según su porcentaje — puro bcmath, nunca float, para que
 * la suma de las partes reconcilie EXACTO contra el monto original (regla
 * innegociable: partida doble exacta en las 3 monedas). El remanente de
 * truncar a 2 decimales lo absorbe la ÚLTIMA línea (por `position`), no la
 * de mayor porcentaje ni la primera — simple y determinístico.
 */
class CostAllocationSplitter
{
    /**
     * @param  Collection<int, CostAllocationRuleLine>  $ruleLines  ordenadas por position
     * @return array<int, string>  cost_center_id => monto (2 decimales), suma exacta a $total
     */
    public function split(string $total, Collection $ruleLines): array
    {
        $lines = $ruleLines->values();
        $lastIndex = $lines->count() - 1;
        $result = [];
        $running = '0.00';

        foreach ($lines as $i => $ruleLine) {
            if ($i === $lastIndex) {
                $amount = bcsub($total, $running, 2);
            } else {
                $raw = bcdiv(bcmul($total, (string) $ruleLine->percentage, 10), '100', 10);
                $amount = bcadd($raw, '0', 2);
                $running = bcadd($running, $amount, 2);
            }

            $result[$ruleLine->cost_center_id] = $amount;
        }

        return $result;
    }

    /**
     * @param  Collection<int, CostAllocationRuleLine>  $ruleLines
     */
    public function sumsTo100(Collection $ruleLines): bool
    {
        $sum = $ruleLines->reduce(fn (string $carry, CostAllocationRuleLine $line) => bcadd($carry, (string) $line->percentage, 2), '0.00');

        return bccomp($sum, '100.00', 2) === 0;
    }
}
