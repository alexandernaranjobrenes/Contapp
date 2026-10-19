<?php

namespace App\Domains\Billing\Support;

/**
 * Aritmética de los montos del comprobante, en texto y con bcmath.
 *
 * Un float pierde exactitud justo donde el XML no la perdona: el tipo
 * DecimalDineroType admite 18 dígitos con 5 decimales, y un double guarda
 * unos 15. bcmath trunca en vez de redondear, así que el redondeo «mitad
 * hacia arriba» se hace a mano: es el que Hacienda usa en sus validaciones.
 */
final class FiscalDecimal
{
    public static function round(string|int|float $value, int $scale = 5): string
    {
        $value = self::normalize($value);
        $half = '0.'.str_repeat('0', $scale).'5';
        $sign = str_starts_with($value, '-') ? '-' : '';

        $rounded = bcadd(ltrim($value, '-'), $half, $scale);

        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $sign.$rounded;
    }

    public static function mul(string $a, string $b, int $scale = 5): string
    {
        return self::round(bcmul(self::normalize($a), self::normalize($b), $scale + 6), $scale);
    }

    public static function div(string $a, string $b, int $scale = 5): string
    {
        if (bccomp(self::normalize($b), '0', 10) === 0) {
            throw new \DivisionByZeroError('División entre cero en un monto fiscal.');
        }

        return self::round(bcdiv(self::normalize($a), self::normalize($b), $scale + 6), $scale);
    }

    /** base × tarifa / 100, a cinco decimales. */
    public static function percentOf(string $base, string $rate, int $scale = 5): string
    {
        return self::round(bcdiv(bcmul(self::normalize($base), self::normalize($rate), 12), '100', 12), $scale);
    }

    /** Para el XML: siempre con la escala exacta, nunca en notación científica. */
    public static function format(string|int|float|null $value, int $scale = 5): string
    {
        return self::round($value ?? '0', $scale);
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return bccomp(self::normalize($value ?? '0'), '0', 10) === 0;
    }

    /**
     * Un valor de la base (decimal como texto) o un número de PHP, como texto
     * que bcmath entiende. Un float se pasa por sprintf con suficientes
     * decimales en vez de (string), que daría «1.0E-5».
     */
    private static function normalize(string|int|float $value): string
    {
        if (is_float($value)) {
            $value = sprintf('%.10F', $value);
        }

        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            return '0';
        }

        // «1e3» es numérico para PHP pero no para bcmath.
        if (stripos($value, 'e') !== false) {
            $value = sprintf('%.10F', (float) $value);
        }

        return $value;
    }
}
