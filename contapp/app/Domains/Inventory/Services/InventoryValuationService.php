<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\DataTransferObjects\InventoryValuationResult;
use App\Domains\Inventory\DataTransferObjects\InventoryValuationRow;
use Illuminate\Support\Facades\DB;

/**
 * Existencias valorizadas A UNA FECHA: cuánto valía el inventario al corte,
 * por artículo y almacén. Es el reporte que amarra el módulo de inventario
 * con la cuenta contable de inventario del balance general.
 *
 * ── La decisión de fondo de este reporte ──────────────────────────────────
 *
 * El valor NO se toma de `items.avg_cost_local` ni de las columnas
 * `avg_cost_*_after` / `balance_quantity` del kardex.
 *
 *   - `items.avg_cost_local` es el promedio de HOY. Multiplicarlo por la
 *     cantidad que había en una fecha pasada valúa el inventario de ayer a
 *     precios de hoy, que es sencillamente otro número.
 *   - Las columnas `*_after` del kardex son foto de auditoría y la Fase 0 las
 *     declaró explícitamente "nunca fuente de verdad para un reporte"
 *     (docs/decisiones.md 2026-09-13). Reusarlas acá sería contradecir esa
 *     decisión, y además son frágiles: un movimiento contabilizado con fecha
 *     retroactiva deja esa foto desordenada respecto de la fecha.
 *
 * Se deriva de los movimientos, que es lo que el resto del proyecto ya hace
 * con todo saldo:
 *
 *     cantidad = Σ entradas − Σ salidas   (hasta la fecha de corte)
 *     valor    = Σ total_cost de entradas − Σ total_cost de salidas
 *
 * Eso es exacto, no aproximado: `total_cost_local` de cada fila es
 * literalmente el monto que esa misma operación llevó al mayor. Por
 * construcción, el total del reporte tiene que ser igual al saldo de las
 * cuentas de inventario en esa fecha — y hay un test que lo comprueba, que
 * es la razón de ser del reporte.
 *
 * El costo unitario de la fila es derivado (valor / cantidad), no leído: a
 * una fecha pasada el promedio vigente era ese, no el actual.
 *
 * ── Rendimiento ───────────────────────────────────────────────────────────
 *
 * UNA sola consulta agregada para toda la compañía, mismo criterio que
 * TrialBalanceService: recorrer artículo por artículo sería N+1 sobre la
 * tabla que más crece del módulo. El filtro por fecha entra por
 * `(company_id, posting_date)`; conviene vigilar ese índice cuando el kardex
 * pase de algunos cientos de miles de filas.
 */
class InventoryValuationService
{
    public function build(
        Company $company,
        string $asOf,
        ?int $warehouseId = null,
        ?int $itemGroupId = null,
        bool $hideZero = true,
    ): InventoryValuationResult {
        $signedQuantity = "CASE WHEN stock_journals.direction = 'in' THEN stock_journals.quantity ELSE -stock_journals.quantity END";
        $signedLocal = "CASE WHEN stock_journals.direction = 'in' THEN stock_journals.total_cost_local ELSE -stock_journals.total_cost_local END";
        $signedForeign = "CASE WHEN stock_journals.direction = 'in' THEN stock_journals.total_cost_foreign ELSE -stock_journals.total_cost_foreign END";

        $rows = DB::table('stock_journals')
            ->join('items', 'items.id', '=', 'stock_journals.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_journals.warehouse_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.uom_id')
            // company_id del kardex, no del artículo: es la columna indexada
            // junto con la fecha, y el aislamiento por compañía de un reporte
            // es obligatorio, no opcional (CLAUDE.md secc. 9).
            ->where('stock_journals.company_id', $company->id)
            ->whereDate('stock_journals.posting_date', '<=', $asOf)
            ->when($warehouseId !== null, fn ($q) => $q->where('stock_journals.warehouse_id', $warehouseId))
            ->when($itemGroupId !== null, fn ($q) => $q->where('items.item_group_id', $itemGroupId))
            ->groupBy(
                'stock_journals.item_id', 'stock_journals.warehouse_id',
                'items.code', 'items.name', 'item_groups.name', 'units_of_measure.code', 'warehouses.code',
            )
            ->orderBy('items.code')
            ->orderBy('warehouses.code')
            ->get([
                'stock_journals.item_id',
                'stock_journals.warehouse_id',
                'items.code as item_code',
                'items.name as item_name',
                'item_groups.name as item_group',
                'units_of_measure.code as uom',
                'warehouses.code as warehouse_code',
                DB::raw("SUM({$signedQuantity}) as quantity"),
                DB::raw("SUM({$signedLocal}) as value_local"),
                DB::raw("SUM({$signedForeign}) as value_foreign"),
            ]);

        $result = [];
        $totalLocal = '0.00';
        $totalForeign = '0.00';

        foreach ($rows as $row) {
            $quantity = $this->quantity((string) $row->quantity);
            $valueLocal = $this->money((string) $row->value_local);
            $valueForeign = $this->money((string) $row->value_foreign);

            // Un artículo que entró y salió completo deja filas de kardex pero
            // existencia cero: es ruido en un reporte de existencias, salvo
            // que se pida verlo (un saldo en cero con valor distinto de cero
            // es justamente el síntoma que hay que poder cazar).
            $isEmpty = bccomp($quantity, '0.000000', 6) === 0 && bccomp($valueLocal, '0.00', 2) === 0;

            if ($hideZero && $isEmpty) {
                continue;
            }

            $result[] = new InventoryValuationRow(
                itemId: (int) $row->item_id,
                itemCode: (string) $row->item_code,
                itemName: (string) $row->item_name,
                itemGroup: $row->item_group,
                uom: $row->uom,
                warehouseId: (int) $row->warehouse_id,
                warehouseCode: (string) $row->warehouse_code,
                quantity: $quantity,
                valueLocal: $valueLocal,
                valueForeign: $valueForeign,
                unitCostLocal: $this->unitCost($valueLocal, $quantity),
            );

            $totalLocal = bcadd($totalLocal, $valueLocal, 2);
            $totalForeign = bcadd($totalForeign, $valueForeign, 2);
        }

        return new InventoryValuationResult($asOf, $result, $totalLocal, $totalForeign);
    }

    /**
     * Costo unitario implícito al corte. Con existencia en cero no hay
     * división posible: se devuelve cero en vez de reventar, y el valor
     * residual (si lo hubiera) queda visible en su propia columna, que es
     * justamente lo que hay que investigar cuando aparece.
     */
    private function unitCost(string $valueLocal, string $quantity): string
    {
        if (bccomp($quantity, '0.000000', 6) === 0) {
            return '0.000000';
        }

        return bcdiv($valueLocal, $quantity, 6);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function quantity(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
