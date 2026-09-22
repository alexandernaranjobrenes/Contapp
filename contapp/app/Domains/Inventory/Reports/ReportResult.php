<?php

namespace App\Domains\Inventory\Reports;

/**
 * Lo que devuelve cualquier reporte de inventario: sus columnas, sus
 * renglones y —si tiene— su pie de totales y una nota que explica lo que se
 * está viendo.
 *
 * Que las tres salidas (pantalla, XLSX, PDF) consuman esta misma estructura
 * es lo que garantiza que digan lo mismo. Es el checklist de CLAUDE.md secc.
 * 9 llevado un paso más allá: no solo la misma fuente de datos, sino el
 * mismo objeto.
 */
class ReportResult
{
    /**
     * @param  ReportColumn[]  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals  clave de columna => valor
     * @param  string[]  $notes  advertencias o aclaraciones para el lector
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $notes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Suma las columnas marcadas como totalizables. Se calcula acá y no en
     * cada reporte para que ninguno se olvide de un pie que declaró.
     *
     * @return array<string, float>
     */
    public function computedTotals(): array
    {
        $totals = [];

        foreach ($this->columns as $column) {
            if (! $column->totalizable) {
                continue;
            }

            // Un total declarado por el reporte gana: hay pies que NO son
            // la suma de la columna (un porcentaje promedio, una rotación
            // del conjunto) y sumarlos daría un número sin sentido.
            $totals[$column->key] = array_key_exists($column->key, $this->totals)
                ? (float) $this->totals[$column->key]
                : array_sum(array_map(fn (array $row) => (float) ($row[$column->key] ?? 0), $this->rows));
        }

        return [...$totals, ...array_map(fn ($v) => (float) $v, $this->totals)];
    }
}
