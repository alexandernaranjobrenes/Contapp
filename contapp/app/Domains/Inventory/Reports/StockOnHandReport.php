<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Qué hay, dónde, y cuánto de eso está realmente libre.
 *
 * ── En qué se diferencia de "Existencias valorizadas" ────────────────────
 *
 * Aquel reporte responde "cuánto valía el inventario AL corte", derivándolo
 * del kardex, y sirve para cuadrar contra el mayor. Este responde "qué
 * tengo AHORA y con qué puedo contar", y su número protagonista no es la
 * existencia sino el disponible:
 *
 *     disponible = existencia − apartado + en camino
 *
 * Los dos son necesarios y ninguno reemplaza al otro: uno es contable y
 * mira hacia atrás, el otro es operativo y mira hacia adelante.
 */
class StockOnHandReport implements InventoryReport
{
    public function code(): string
    {
        return 'stock-on-hand';
    }

    public function label(): string
    {
        return 'Existencias y compromisos';
    }

    public function description(): string
    {
        return 'Lo que hay en cada almacén, cuánto está apartado por pedidos, cuánto viene en camino y cuánto queda libre.';
    }

    public function decision(): string
    {
        return 'Si se puede prometer mercancía a un cliente hoy, y qué artículos ya están por debajo de su mínimo.';
    }

    public function group(): string
    {
        return 'Existencias';
    }

    /**
     * Columnas de identidad que se congelan al desplazar la tabla de
     * lado: código y nombre.
     */
    public function frozenColumns(): int
    {
        return 2;
    }

    public function filters(): array
    {
        return [
            new ReportFilter('warehouse_id', 'Almacén', ReportFilter::SELECT, optionSource: 'warehouses'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('hide_zero', 'Ocultar existencias en cero', ReportFilter::BOOLEAN, default: true),
            new ReportFilter('only_below_minimum', 'Solo lo que está bajo mínimo', ReportFilter::BOOLEAN,
                hint: 'Deja a la vista únicamente lo que hay que reponer.'),
            new ReportFilter('search', 'Código o nombre', ReportFilter::TEXT),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $rows = DB::table('item_warehouses')
            ->join('items', 'items.id', '=', 'item_warehouses.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'item_warehouses.warehouse_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.uom_id')
            ->where('items.company_id', $company->id)
            ->where('items.is_inventory_item', true)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouses.id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('items.code', 'like', "%{$v}%")
                ->orWhere('items.name', 'like', "%{$v}%")))
            ->when($filters['hide_zero'] ?? true, fn ($q) => $q->where(fn ($w) => $w
                ->where('item_warehouses.on_hand', '!=', 0)
                ->orWhere('item_warehouses.reserved', '!=', 0)
                ->orWhere('item_warehouses.ordered', '!=', 0)))
            ->orderBy('items.code')
            ->orderBy('warehouses.code')
            ->get([
                'items.code', 'items.name', 'items.avg_cost_local',
                'item_groups.name as group_name',
                'units_of_measure.code as uom',
                'warehouses.code as warehouse_code',
                'item_warehouses.on_hand', 'item_warehouses.reserved', 'item_warehouses.ordered',
                'item_warehouses.minimum_stock as wh_minimum',
                'items.minimum_stock as item_minimum',
            ])
            ->map(function ($row) {
                $onHand = (float) $row->on_hand;
                $reserved = (float) $row->reserved;
                $ordered = (float) $row->ordered;
                $available = $onHand - $reserved + $ordered;

                // Precedencia almacén → ficha, la misma del reorden.
                $minimum = $row->wh_minimum !== null ? (float) $row->wh_minimum : (float) $row->item_minimum;

                return [
                    'code' => $row->code,
                    'name' => $row->name,
                    'group_name' => $row->group_name,
                    'warehouse_code' => $row->warehouse_code,
                    'uom' => $row->uom,
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'ordered' => $ordered,
                    'available' => $available,
                    'minimum_stock' => $minimum,
                    // Solo se marca si hay mínimo configurado: cero significa
                    // "sin control de reorden", no "el piso es cero".
                    'below_minimum' => $minimum > 0 && $available <= $minimum ? 'Sí' : '',
                    'avg_cost_local' => (float) $row->avg_cost_local,
                    'value_local' => $onHand * (float) $row->avg_cost_local,
                ];
            })
            ->when(
                $filters['only_below_minimum'] ?? false,
                fn ($c) => $c->filter(fn (array $r) => $r['below_minimum'] === 'Sí')
            )
            ->values()
            ->all();

        return new ReportResult(
            columns: [
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 28),
                new ReportColumn('group_name', 'Grupo'),
                new ReportColumn('warehouse_code', 'Almacén'),
                new ReportColumn('uom', 'U/M'),
                new ReportColumn('on_hand', 'Existencia', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('reserved', 'Apartado', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('ordered', 'En camino', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('available', 'Disponible', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('minimum_stock', 'Mínimo', ReportColumn::NUMBER),
                new ReportColumn('below_minimum', 'Bajo mínimo'),
                new ReportColumn('avg_cost_local', 'Costo prom.', ReportColumn::MONEY),
                new ReportColumn('value_local', 'Valor', ReportColumn::MONEY, totalizable: true),
            ],
            rows: $rows,
            notes: [
                'Disponible = existencia − apartado + en camino. Lo apartado por un pedido ya tiene dueño y no cubre demanda nueva.',
                'El valor usa el costo promedio ACTUAL. Para el valor a una fecha de corte, usá "Existencias valorizadas".',
            ],
        );
    }
}
