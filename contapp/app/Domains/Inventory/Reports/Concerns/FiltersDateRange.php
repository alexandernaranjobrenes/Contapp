<?php

namespace App\Domains\Inventory\Reports\Concerns;

use Illuminate\Database\Query\Builder;

/**
 * Acota una consulta a un rango de fechas con un intervalo SEMIABIERTO:
 *
 *     columna >= desde   Y   columna < (hasta + 1 día)
 *
 * ── Por qué no un BETWEEN ────────────────────────────────────────────────
 *
 * Porque una columna de fecha puede traer hora. Con `BETWEEN '2026-09-01'
 * AND '2026-09-22'`, una fila del 22 a las 00:00:00 queda FUERA: comparada
 * como texto, '2026-09-22 00:00:00' es mayor que '2026-09-22'. El último día
 * del rango desaparece del reporte y nadie lo nota, porque el reporte no
 * falla — simplemente devuelve de menos.
 *
 * ── Por qué no whereDate() ───────────────────────────────────────────────
 *
 * whereDate() envuelve la columna en una función y MySQL deja de poder usar
 * el índice. En `stock_journals`, donde el índice es (company_id, item_id,
 * warehouse_id, posting_date), eso convierte una consulta acotada en un
 * barrido de toda la tabla. El intervalo semiabierto es correcto en los dos
 * motores y conserva el índice.
 */
trait FiltersDateRange
{
    protected function inDateRange(Builder $query, string $column, string $from, string $to): Builder
    {
        return $query
            ->where($column, '>=', $from)
            ->where($column, '<', \Carbon\Carbon::parse($to)->addDay()->format('Y-m-d'));
    }

    /**
     * Todo lo ocurrido HASTA una fecha inclusive, con el mismo cuidado.
     */
    protected function upToDate(Builder $query, string $column, string $date): Builder
    {
        return $query->where($column, '<', \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d'));
    }
}
