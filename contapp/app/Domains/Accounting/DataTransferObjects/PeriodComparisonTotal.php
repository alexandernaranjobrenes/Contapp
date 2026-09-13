<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

/**
 * Par de montos (uno por periodo) + su variación, reutilizado tanto por cada
 * línea de cuenta como por cada total de sección del comparativo — evita
 * repetir el mismo cálculo de variancia ocho veces en PeriodComparisonResult.
 */
class PeriodComparisonTotal implements JsonSerializable
{
    public function __construct(
        public readonly string $period1,
        public readonly string $period2,
        public readonly string $variance,
        public readonly ?string $variancePercent,
    ) {}

    public static function of(string $amount1, string $amount2): self
    {
        $variance = bcsub($amount2, $amount1, 2);

        // Sin base de comparación (periodo 1 en cero) el % de variación no
        // tiene un valor matemáticamente sensato (división entre cero) — se
        // deja null en vez de forzar un 0% o un infinito engañoso; la UI lo
        // muestra como "—".
        $variancePercent = bccomp($amount1, '0.00', 2) !== 0
            ? bcmul(bcdiv($variance, $amount1, 6), '100', 2)
            : null;

        return new self($amount1, $amount2, $variance, $variancePercent);
    }

    public function jsonSerialize(): array
    {
        return [
            'period_1' => $this->period1,
            'period_2' => $this->period2,
            'variance' => $this->variance,
            'variance_percent' => $this->variancePercent,
        ];
    }
}
