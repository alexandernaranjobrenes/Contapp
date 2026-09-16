<?php

namespace App\Domains\Inventory\Services;

/**
 * Decide cuánto de un costo sobrevenido —flete, arancel, diferencia de precio
 * del proveedor— puede capitalizarse al inventario y cuánto tiene que ir a
 * resultados.
 *
 * El PDF original decía que un costo de importación "incrementa el costo
 * unitario promedio", sin más. Eso solo es cierto mientras la mercancía siga
 * en existencia: si ya se vendió, no hay activo que incrementar y
 * capitalizarlo inflaría el inventario con mercancía que no existe. SAP B1
 * manda esa porción a una cuenta de diferencia de precio, y es lo que se hace
 * acá (docs/decisiones.md 2026-09-13 Fase 5).
 *
 * La proporción se mide contra la existencia ACTUAL del artículo, no contra
 * capas de compra: el costeo de este proyecto es promedio ponderado móvil
 * global (decisión de Fase 0), así que no existe "quedan 40 de AQUELLAS 100"
 * — solo "quedan 40 en total".
 */
class StockRevaluationSplitter
{
    /**
     * @return array{capitalized: string, expensed: string}
     */
    public function split(string $allocated, string $receivedQuantity, string $onHandQuantity): array
    {
        $zero = '0.00';

        if (bccomp($allocated, $zero, 2) === 0) {
            return ['capitalized' => $zero, 'expensed' => $zero];
        }

        // Sin existencia no hay nada que capitalizar: todo el costo llega
        // tarde para una mercancía que ya salió.
        if (bccomp($onHandQuantity, '0.000000', 6) <= 0) {
            return ['capitalized' => $zero, 'expensed' => $allocated];
        }

        // La existencia cubre todo lo recibido: se capitaliza completo.
        if (bccomp($onHandQuantity, $receivedQuantity, 6) >= 0) {
            return ['capitalized' => $allocated, 'expensed' => $zero];
        }

        $capitalized = $this->money(
            bcdiv(bcmul($allocated, $onHandQuantity, 12), $receivedQuantity, 12)
        );

        return [
            'capitalized' => $capitalized,
            // Por resta, nunca por su propia división: así las dos partes
            // siempre suman exactamente el total repartido.
            'expensed' => bcsub($allocated, $capitalized, 2),
        ];
    }

    /**
     * Reparte un total entre las líneas en proporción a su valor. La última
     * línea absorbe el residuo del redondeo para que la suma de las partes sea
     * exactamente el total: si no, el asiento no cuadraría por céntimos.
     *
     * @param  string[]  $lineValues  valor de cada línea, en el mismo orden
     * @return string[]
     */
    public function allocateByValue(string $total, array $lineValues): array
    {
        $totalValue = array_reduce($lineValues, fn ($carry, $value) => bcadd($carry, $value, 6), '0.000000');

        if (bccomp($totalValue, '0.000000', 6) <= 0) {
            throw new \InvalidArgumentException('No se puede repartir un costo entre líneas sin valor.');
        }

        $allocations = [];
        $assigned = '0.00';
        $lastIndex = array_key_last($lineValues);

        foreach ($lineValues as $index => $value) {
            if ($index === $lastIndex) {
                $allocations[$index] = bcsub($total, $assigned, 2);

                continue;
            }

            $share = $this->money(bcdiv(bcmul($total, $value, 12), $totalValue, 12));
            $allocations[$index] = $share;
            $assigned = bcadd($assigned, $share, 2);
        }

        return $allocations;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
