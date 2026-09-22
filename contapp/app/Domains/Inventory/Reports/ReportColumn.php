<?php

namespace App\Domains\Inventory\Reports;

/**
 * Una columna de un reporte de inventario: cómo se llama, de qué clave del
 * renglón sale y cómo se presenta.
 *
 * El formato vive acá y no en cada salida porque hay TRES —pantalla, XLSX y
 * PDF— y si cada una decidiera por su cuenta, el mismo número saldría con
 * distintos decimales según dónde se mire. Es el error que hace que un
 * usuario compare dos hojas y crea que el sistema se contradice.
 */
class ReportColumn
{
    /** Cómo se alinea y con cuántos decimales se muestra. */
    public const TEXT = 'text';

    public const NUMBER = 'number';      // cantidades: hasta 6 decimales

    public const MONEY = 'money';        // importes: 2 decimales

    public const PERCENT = 'percent';    // porcentajes: 1 decimal

    public const DATE = 'date';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $format = self::TEXT,
        /** Si suma en el pie del reporte. Solo tiene sentido en números. */
        public readonly bool $totalizable = false,
        /** Ancho sugerido en caracteres, para que el PDF no parta títulos. */
        public readonly ?int $width = null,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->format, [self::NUMBER, self::MONEY, self::PERCENT], true);
    }

    /**
     * El valor ya presentado. Las tres salidas llaman a esto.
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this->format) {
            self::NUMBER => number_format((float) $value, $this->decimalsFor((float) $value), ',', '.'),
            self::MONEY => number_format((float) $value, 2, ',', '.'),
            self::PERCENT => number_format((float) $value, 1, ',', '.').' %',
            default => (string) $value,
        };
    }

    /**
     * Una cantidad entera se muestra sin decimales y una fraccionaria con los
     * que tenga, hasta 6. Mostrar "10,000000" cajas donde el usuario escribió
     * "10" hace que una lista de enteros se vuelva ilegible.
     */
    private function decimalsFor(float $value): int
    {
        if ($value === floor($value)) {
            return 0;
        }

        return 2;
    }
}
