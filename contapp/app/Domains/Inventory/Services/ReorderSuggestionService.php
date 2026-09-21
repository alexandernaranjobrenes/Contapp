<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué hay que comprar y cuánto: los artículos cuyo disponible cayó a su
 * mínimo o por debajo, con la cantidad que los devuelve a su nivel objetivo.
 *
 * ── La fórmula, y por qué cada término está ahí ──────────────────────────
 *
 *     disponible = on_hand − reserved + ordered
 *     falta      = objetivo − disponible        (si disponible <= mínimo)
 *
 * - `− reserved`: lo apartado por un pedido de venta ya tiene dueño. Contarlo
 *   como disponible haría creer que hay mercancía para atender demanda nueva
 *   cuando en realidad está comprometida, y el sistema no avisaría del
 *   quiebre hasta que fuera tarde.
 * - `+ ordered`: lo que viene en camino por una orden de compra abierta sí va
 *   a cubrir la demanda. Omitirlo es el error clásico de este reporte:
 *   sugiere comprar de nuevo algo que ya se pidió, y termina en inventario
 *   duplicado. Es la razón de que la orden de compra tuviera que construirse
 *   antes que esto.
 *
 * El objetivo es `maximum_stock` si está definido, y el mínimo si no: sin un
 * máximo, reponer solo hasta el piso es lo conservador y no inventa una
 * política de compra que nadie configuró.
 *
 * ── Lo que NO hace ───────────────────────────────────────────────────────
 *
 * No calcula el punto de reorden a partir de consumo histórico ni de plazo de
 * entrega. El mínimo lo fija quien conoce el negocio; derivarlo de la demanda
 * pasada es otra decisión —y otra discusión— que este servicio no toma por
 * nadie. Tampoco elige proveedor: no existe proveedor preferido por artículo
 * en el esquema, y suponerlo sería inventar un dato.
 */
class ReorderSuggestionService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Company $company, ?int $warehouseId = null, ?int $itemGroupId = null): Collection
    {
        $rows = DB::table('item_warehouses')
            ->join('items', 'items.id', '=', 'item_warehouses.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'item_warehouses.warehouse_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.uom_id')
            ->where('items.company_id', $company->id)
            // Un mínimo en cero significa "sin control de reorden": no es que
            // el piso sea cero, es que nadie configuró uno. Sin este filtro,
            // todo artículo agotado aparecería como urgente.
            ->where('item_warehouses.minimum_stock', '>', 0)
            ->where('items.status', 'active')
            ->where('items.is_inventory_item', true)
            ->where('warehouses.status', 'active')
            ->when($warehouseId !== null, fn ($q) => $q->where('item_warehouses.warehouse_id', $warehouseId))
            ->when($itemGroupId !== null, fn ($q) => $q->where('items.item_group_id', $itemGroupId))
            ->orderBy('items.code')
            ->orderBy('warehouses.code')
            ->get([
                'item_warehouses.item_id',
                'item_warehouses.warehouse_id',
                'item_warehouses.on_hand',
                'item_warehouses.reserved',
                'item_warehouses.ordered',
                'item_warehouses.minimum_stock',
                'item_warehouses.maximum_stock',
                'items.code as item_code',
                'items.name as item_name',
                'items.avg_cost_local',
                'items.is_purchase_item',
                'item_groups.name as item_group',
                'units_of_measure.code as uom',
                'warehouses.code as warehouse_code',
            ]);

        return $rows
            ->map(fn ($row) => $this->toSuggestion($row))
            ->filter()
            ->sortBy(fn (array $s) => $s['coverage'])
            ->values();
    }

    /**
     * @return array<string, mixed>|null  null si la fila no está en quiebre
     */
    private function toSuggestion($row): ?array
    {
        $onHand = $this->qty((string) $row->on_hand);
        $reserved = $this->qty((string) $row->reserved);
        $ordered = $this->qty((string) $row->ordered);
        $minimum = $this->qty((string) $row->minimum_stock);

        $available = bcadd(bcsub($onHand, $reserved, 6), $ordered, 6);

        // Dispara al tocar el mínimo, no al bajarlo: quedarse exactamente en
        // el piso ya es la señal de reponer.
        if (bccomp($available, $minimum, 6) > 0) {
            return null;
        }

        $target = $row->maximum_stock !== null
            ? $this->qty((string) $row->maximum_stock)
            : $minimum;

        $suggested = bcsub($target, $available, 6);

        // Con el objetivo por debajo de lo disponible no hay nada que pedir.
        // Pasa si alguien fija un máximo menor que el mínimo; se ignora en
        // vez de sugerir una cantidad negativa.
        if (bccomp($suggested, '0.000000', 6) <= 0) {
            return null;
        }

        return [
            'item_id' => (int) $row->item_id,
            'item_code' => $row->item_code,
            'item_name' => $row->item_name,
            'item_group' => $row->item_group,
            'uom' => $row->uom,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_code' => $row->warehouse_code,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'ordered' => $ordered,
            'available' => $available,
            'minimum_stock' => $minimum,
            'maximum_stock' => $row->maximum_stock !== null ? $this->qty((string) $row->maximum_stock) : null,
            'suggested_quantity' => $suggested,
            'avg_cost_local' => number_format((float) $row->avg_cost_local, 6, '.', ''),
            'estimated_cost' => number_format((float) $suggested * (float) $row->avg_cost_local, 2, '.', ''),
            'is_purchase_item' => (bool) $row->is_purchase_item,
            // Qué tan por debajo del mínimo está, en proporción. Ordena la
            // lista: lo más descubierto primero, que es lo que hay que
            // atender antes.
            'coverage' => (float) $minimum > 0 ? (float) $available / (float) $minimum : 0.0,
        ];
    }

    private function qty(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
