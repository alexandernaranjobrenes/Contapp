<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo de artículos con todo lo que tiene configurado cada uno.
 *
 * No es "la lista de artículos" a secas: es la foto de la CONFIGURACIÓN, y
 * su utilidad real es encontrar lo que está a medio configurar antes de que
 * moleste. Un artículo de venta sin CAByS obliga a teclearlo en cada
 * factura; uno sin mínimo nunca va a aparecer en la sugerencia de compra;
 * uno sin precio nunca se va a precargar. Los tres filtros de "incompletos"
 * existen para eso.
 */
class ItemCatalogReport implements InventoryReport
{
    public function code(): string
    {
        return 'item-catalog';
    }

    public function label(): string
    {
        return 'Lista de artículos';
    }

    public function description(): string
    {
        return 'El catálogo completo con su grupo, unidad, datos fiscales, niveles de reposición y costo promedio.';
    }

    public function decision(): string
    {
        return 'Qué artículos están a medio configurar: sin CAByS no se factura sin teclearlo, sin mínimo nunca aparecen en la sugerencia de compra.';
    }

    public function group(): string
    {
        return 'Catálogo';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, default: 'active', options: [
                'active' => 'Activos', 'inactive' => 'Inactivos',
            ]),
            new ReportFilter('kind', 'Tipo', ReportFilter::SELECT, options: [
                'inventory' => 'De inventario', 'service' => 'Servicios',
                'sales' => 'De venta', 'purchase' => 'De compra',
            ]),
            new ReportFilter('missing', 'Configuración incompleta', ReportFilter::SELECT, options: [
                'cabys' => 'Se vende y no tiene CAByS',
                'minimum' => 'De inventario y sin mínimo',
                'tax' => 'Sin indicador de impuesto',
            ], hint: 'Filtra solo lo que falta configurar, que es para lo que más sirve este reporte.'),
            new ReportFilter('search', 'Código o nombre', ReportFilter::TEXT),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $rows = DB::table('items')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.uom_id')
            ->leftJoin('tax_rates', 'tax_rates.id', '=', 'items.tax_rate_id')
            ->where('items.company_id', $company->id)
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('items.status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('items.code', 'like', "%{$v}%")
                ->orWhere('items.name', 'like', "%{$v}%")))
            ->when($filters['kind'] ?? null, fn ($q, $v) => match ($v) {
                'inventory' => $q->where('items.is_inventory_item', true),
                'service' => $q->where('items.is_inventory_item', false),
                'sales' => $q->where('items.is_sales_item', true),
                'purchase' => $q->where('items.is_purchase_item', true),
                default => $q,
            })
            ->when($filters['missing'] ?? null, fn ($q, $v) => match ($v) {
                'cabys' => $q->where('items.is_sales_item', true)
                    ->where(fn ($w) => $w->whereNull('items.cabys_code')->orWhere('items.cabys_code', '')),
                'minimum' => $q->where('items.is_inventory_item', true)->where('items.minimum_stock', '<=', 0),
                'tax' => $q->whereNull('items.tax_rate_id'),
                default => $q,
            })
            ->orderBy('items.code')
            ->get([
                'items.code', 'items.name', 'items.barcode',
                'item_groups.name as group_name',
                'units_of_measure.code as uom',
                'items.is_inventory_item', 'items.is_sales_item', 'items.is_purchase_item',
                'items.tracks_lots', 'items.tracks_serials',
                'items.minimum_stock', 'items.maximum_stock',
                'items.cabys_code', 'items.iva_rate_code',
                'tax_rates.code as tax_code',
                'items.avg_cost_local', 'items.status',
            ])
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'group_name' => $row->group_name,
                'uom' => $row->uom,
                'kind' => $this->kindLabel($row),
                'tracking' => $this->trackingLabel($row),
                'minimum_stock' => (float) $row->minimum_stock,
                'maximum_stock' => $row->maximum_stock !== null ? (float) $row->maximum_stock : null,
                'cabys_code' => $row->cabys_code,
                'tax_code' => $row->tax_code,
                'avg_cost_local' => (float) $row->avg_cost_local,
                'status' => $row->status === 'active' ? 'Activo' : 'Inactivo',
            ])
            ->all();

        return new ReportResult(
            columns: [
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 30),
                new ReportColumn('group_name', 'Grupo'),
                new ReportColumn('uom', 'U/M'),
                new ReportColumn('kind', 'Tipo'),
                new ReportColumn('tracking', 'Trazabilidad'),
                new ReportColumn('minimum_stock', 'Mínimo', ReportColumn::NUMBER),
                new ReportColumn('maximum_stock', 'Máximo', ReportColumn::NUMBER),
                new ReportColumn('cabys_code', 'CAByS'),
                new ReportColumn('tax_code', 'Impuesto'),
                new ReportColumn('avg_cost_local', 'Costo prom.', ReportColumn::MONEY),
                new ReportColumn('status', 'Estado'),
            ],
            rows: $rows,
            notes: [
                'El costo promedio es global por artículo, no por almacén: lo mantiene el motor de movimientos.',
            ],
        );
    }

    private function kindLabel($row): string
    {
        if (! $row->is_inventory_item) {
            return 'Servicio';
        }

        $parts = [];
        if ($row->is_sales_item) {
            $parts[] = 'venta';
        }
        if ($row->is_purchase_item) {
            $parts[] = 'compra';
        }

        return $parts === [] ? 'Inventario' : 'Inventario ('.implode(' y ', $parts).')';
    }

    private function trackingLabel($row): string
    {
        $parts = [];
        if ($row->tracks_lots) {
            $parts[] = 'lotes';
        }
        if ($row->tracks_serials) {
            $parts[] = 'series';
        }

        return $parts === [] ? '—' : ucfirst(implode(' y ', $parts));
    }
}
