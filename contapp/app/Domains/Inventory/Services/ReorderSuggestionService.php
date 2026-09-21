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
        // Se maneja desde items × almacenes y NO desde item_warehouses: esa
        // tabla solo tiene fila donde el artículo ya se movió alguna vez, así
        // que un artículo nuevo con mínimo en su ficha —el caso más obvio,
        // dar de alta un producto y esperar que el sistema mande comprar el
        // primer lote— habría sido invisible.
        //
        // El par se incluye si el artículo ya se stockeó ahí (existe la fila)
        // o si es el almacén predeterminado. Sin esa segunda condición un
        // artículo sin movimientos no aparecería; sin la primera, un mínimo
        // de ficha se multiplicaría por cada almacén de la compañía y
        // llenaría la lista de ruido.
        //
        // Rendimiento: el producto cartesiano se poda temprano por
        // company_id, por el estado del artículo y por el mínimo efectivo.
        // Con catálogos grandes y muchos almacenes conviene vigilarlo.
        $rows = DB::table('items')
            ->crossJoin('warehouses')
            ->leftJoin('item_warehouses', function ($join) {
                $join->on('item_warehouses.item_id', '=', 'items.id')
                    ->on('item_warehouses.warehouse_id', '=', 'warehouses.id');
            })
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.uom_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id)
            ->where(fn ($q) => $q
                ->whereNotNull('item_warehouses.id')
                ->orWhere('warehouses.is_default', true))
            // El mínimo efectivo sale de la precedencia almacén → ficha, así
            // que el filtro tiene que mirar la misma expresión: un artículo
            // con mínimo en su ficha entra aunque el almacén no defina nada.
            //
            // Sigue valiendo que un mínimo efectivo de 0 significa "sin
            // control de reorden" y no "el piso es cero": sin eso, todo
            // artículo agotado del catálogo aparecería como urgente.
            ->whereRaw('COALESCE(item_warehouses.minimum_stock, items.minimum_stock) > 0')
            ->where('items.status', 'active')
            ->where('items.is_inventory_item', true)
            ->where('warehouses.status', 'active')
            // Sobre warehouses.id y no sobre item_warehouses.warehouse_id:
            // esa columna viene nula en las filas que entran por la rama del
            // almacén predeterminado, y filtrar por ahí las descartaría.
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouses.id', $warehouseId))
            ->when($itemGroupId !== null, fn ($q) => $q->where('items.item_group_id', $itemGroupId))
            ->orderBy('items.code')
            ->orderBy('warehouses.code')
            ->get([
                'items.id as item_id',
                'warehouses.id as warehouse_id',
                DB::raw('COALESCE(item_warehouses.on_hand, 0) as on_hand'),
                DB::raw('COALESCE(item_warehouses.reserved, 0) as reserved'),
                DB::raw('COALESCE(item_warehouses.ordered, 0) as ordered'),
                'item_warehouses.minimum_stock',
                'item_warehouses.maximum_stock',
                'items.minimum_stock as item_minimum_stock',
                'items.maximum_stock as item_maximum_stock',
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

        // Precedencia almacén → ficha. El almacén gana cuando define algo,
        // INCLUIDO el cero: poner 0 en una bodega de tránsito es la forma de
        // excluirla del reorden aunque el artículo tenga mínimo en su ficha.
        $overridden = $row->minimum_stock !== null;
        $minimum = $this->qty((string) ($row->minimum_stock ?? $row->item_minimum_stock));

        if (bccomp($minimum, '0.000000', 6) <= 0) {
            return null;
        }

        $available = bcadd(bcsub($onHand, $reserved, 6), $ordered, 6);

        // Dispara al tocar el mínimo, no al bajarlo: quedarse exactamente en
        // el piso ya es la señal de reponer.
        if (bccomp($available, $minimum, 6) > 0) {
            return null;
        }

        // Misma escalera para el máximo: el del almacén, si no el de la
        // ficha, y si ninguno define nada el objetivo es el propio mínimo.
        $maximum = $row->maximum_stock ?? $row->item_maximum_stock;
        $target = $maximum !== null ? $this->qty((string) $maximum) : $minimum;

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
            'maximum_stock' => $maximum !== null ? $this->qty((string) $maximum) : null,
            // Para que la pantalla pueda distinguir el nivel heredado de la
            // ficha del que este almacén fijó aparte.
            'minimum_is_override' => $overridden,
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
