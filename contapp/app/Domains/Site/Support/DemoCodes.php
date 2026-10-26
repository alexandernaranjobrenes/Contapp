<?php

namespace App\Domains\Site\Support;

/**
 * Los códigos que dibujan las demostraciones de la landing: un código de
 * barras EAN-13 de verdad (se puede leer con un lector) y un QR de adorno,
 * con sus tres esquinas como un QR real pero sin datos adentro.
 */
final class DemoCodes
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Qué tabla (L o G) usa cada uno de los seis primeros dígitos, según el primero. */
    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /**
     * Un EAN-13 a partir de sus 12 primeros dígitos: los 13 con el
     * verificador, y las 95 barras (1 = negra).
     *
     * @return array{digits: string, modules: string}
     */
    public static function ean13(string $twelve): array
    {
        $digits = array_map('intval', str_split($twelve));
        $sum = 0;

        foreach ($digits as $index => $digit) {
            $sum += $index % 2 === 0 ? $digit : $digit * 3;
        }

        $digits[] = (10 - $sum % 10) % 10;
        $parity = self::PARITY[$digits[0]];
        $modules = '101';

        for ($i = 1; $i <= 6; $i++) {
            $modules .= $parity[$i - 1] === 'L' ? self::L[$digits[$i]] : self::G[$digits[$i]];
        }

        $modules .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $modules .= self::R[$digits[$i]];
        }

        return ['digits' => implode('', $digits), 'modules' => $modules.'101'];
    }

    /**
     * Las celdas negras de un QR de adorno de $size × $size: las tres
     * esquinas de un QR y un relleno que siempre sale igual para la misma
     * $seed.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function decorativeQr(int $size = 25, string $seed = 'pivox'): array
    {
        $cells = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $finder = self::finder($x, $y, $size);

                $dark = $finder ?? (crc32("{$seed}:{$x}:{$y}") % 100 < 46);

                if ($dark) {
                    $cells[] = [$x, $y];
                }
            }
        }

        return $cells;
    }

    /** Dentro de una esquina, si la celda es negra; fuera de ellas, null. */
    private static function finder(int $x, int $y, int $size): ?bool
    {
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$left, $top]) {
            $dx = $x - $left;
            $dy = $y - $top;

            // El margen blanco de una fila alrededor de cada esquina.
            if ($dx >= -1 && $dx <= 7 && $dy >= -1 && $dy <= 7) {
                if ($dx < 0 || $dx > 6 || $dy < 0 || $dy > 6) {
                    return false;
                }

                $ring = max(abs($dx - 3), abs($dy - 3));

                return $ring !== 2;
            }
        }

        return null;
    }
}
