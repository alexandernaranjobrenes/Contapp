<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiFormat as F;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\ImportCostDocument;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\InventoryWriteDown;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\LandedCostDocument;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Models\StockCount;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/** Inventario: catálogos, existencias, compras, producción. */
final class InventoryResources
{
    private const PRODUCTION_STATUSES = ['open' => 'Abierta', 'closed' => 'Cerrada', 'cancelled' => 'Cancelada'];

    /** @return list<ContiResource> */
    public static function all(): array
    {
        return [
            self::items(),
            self::stock(),
            self::itemGroups(),
            self::warehouses(),
            self::units(),
            self::priceLists(),
            self::prices(),
            self::lots(),
            self::serials(),
            self::movements(),
            self::kardex(),
            self::purchaseOrders(),
            self::stockCounts(),
            self::writeDowns(),
            self::productionOrders(),
            self::billsOfMaterials(),
            self::landedCosts(),
            self::importCosts(),
            self::glDeterminations(),
        ];
    }

    /** ¿Ve costos? Quien tiene Artículos; quien solo factura o pide, no. */
    private static function seesCosts(): bool
    {
        return app(ContiContext::class)->allowsAny(['inventory.items', 'reports.inventory_valuation', 'inventory.reports']);
    }

    private static function items(): ContiResource
    {
        $row = fn (Item $i) => [
            'id' => $i->id,
            'codigo' => $i->code,
            'nombre' => $i->name,
            'grupo' => F::codeName($i->itemGroup),
            'unidad' => $i->unitOfMeasure?->code,
            'tipo' => $i->is_inventory_item ? 'inventariable' : 'servicio',
            'se_compra' => F::yesNo($i->is_purchase_item),
            'se_vende' => F::yesNo($i->is_sales_item),
            'maneja_lotes' => F::yesNo($i->tracks_lots),
            'maneja_series' => F::yesNo($i->tracks_serials),
            'codigo_barras' => $i->barcode,
            'cabys' => $i->cabys_code,
            'tarifa_iva_hacienda' => $i->iva_rate_code,
            'minimo' => F::number($i->minimum_stock),
            'maximo' => F::number($i->maximum_stock),
            'existencia_total' => F::number($i->stock_levels_sum_on_hand ?? null),
            'costo_promedio_local' => self::seesCosts() ? F::money($i->avg_cost_local) : null,
            'estado' => F::status($i->status),
        ];

        return new ContiResource(
            key: 'articulos',
            label: 'Artículos',
            description: 'El catálogo de artículos y servicios, con existencia total y costo promedio. El detalle trae la existencia por almacén (disponible = existencia − apartado).',
            screens: ['inventory.items', 'inventory.movements', 'inventory.purchase_orders', 'billing.new_invoice', 'billing.sales_orders', 'inventory.price_lists'],
            query: fn () => Item::query()->with(['itemGroup:id,code,name', 'unitOfMeasure:id,code'])->withSum('stockLevels', 'on_hand'),
            row: $row,
            detail: fn (Item $i) => $row($i) + [
                'por_almacen' => $i->stockLevels()->with('warehouse:id,code,name')->get()->map(fn (ItemWarehouse $s) => [
                    'almacen' => F::codeName($s->warehouse),
                    'existencia' => F::number($s->on_hand),
                    'apartado' => F::number($s->reserved),
                    'en_camino' => F::number($s->ordered),
                    'disponible' => F::number(bcsub((string) $s->on_hand, (string) $s->reserved, 6)),
                    'minimo' => F::number($s->minimum_stock),
                    'maximo' => F::number($s->maximum_stock),
                ])->all(),
            ],
            search: ['code', 'name', 'barcode', 'cabys_code'],
            filters: [
                'grupo' => ['código del grupo', ContiFilters::relatedCode('itemGroup')],
                'estado' => ['active | inactive', ContiFilters::equals('status')],
                'tipo' => ['inventariable | servicio', fn (Builder $q, string $v) => $q->where('is_inventory_item', ! str_starts_with(mb_strtolower($v), 'serv'))],
            ],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function stock(): ContiResource
    {
        return new ContiResource(
            key: 'existencias',
            label: 'Existencias por almacén',
            description: 'Lo que hay de cada artículo en cada almacén: existencia, apartado por pedidos, en camino por órdenes de compra y disponible.',
            screens: ['inventory.items', 'inventory.reports', 'billing.sales_orders', 'billing.new_invoice'],
            query: fn () => ItemWarehouse::query()->whereHas('item')->with(['item:id,code,name', 'warehouse:id,code,name']),
            row: fn (ItemWarehouse $s) => [
                'articulo' => F::codeName($s->item),
                'almacen' => F::codeName($s->warehouse),
                'existencia' => F::number($s->on_hand),
                'apartado' => F::number($s->reserved),
                'en_camino' => F::number($s->ordered),
                'disponible' => F::number(bcsub((string) $s->on_hand, (string) $s->reserved, 6)),
                'minimo' => F::number($s->minimum_stock),
                'maximo' => F::number($s->maximum_stock),
            ],
            search: fn (Builder $q, string $term) => $q->whereHas('item', fn (Builder $i) => $i->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")),
            filters: [
                'articulo' => ['código del artículo', ContiFilters::relatedCode('item')],
                'almacen' => ['código del almacén', ContiFilters::relatedCode('warehouse')],
                'con_existencia' => ['sí: solo donde hay existencia', fn (Builder $q, string $v) => ContiFilters::truthy($v) ? $q->where('on_hand', '>', 0) : $q],
            ],
            orderBy: ['item_id' => 'asc', 'warehouse_id' => 'asc'],
            codeColumn: null,
        );
    }

    private static function itemGroups(): ContiResource
    {
        return new ContiResource(
            key: 'grupos-articulos',
            label: 'Grupos de artículos',
            description: 'Los grupos que clasifican los artículos (y pueden definir sus cuentas).',
            screens: ['inventory.item_groups', 'inventory.items'],
            query: fn () => ItemGroup::query()->withCount('items'),
            row: fn (ItemGroup $g) => [
                'id' => $g->id,
                'codigo' => $g->code,
                'nombre' => $g->name,
                'articulos' => $g->items_count,
                'estado' => F::status($g->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function warehouses(): ContiResource
    {
        return new ContiResource(
            key: 'almacenes',
            label: 'Almacenes',
            description: 'Los almacenes (bodegas), cuál es el predeterminado y si manejan ubicaciones.',
            screens: ['inventory.warehouses', 'inventory.items', 'inventory.movements', 'billing.new_invoice', 'billing.sales_orders', 'inventory.purchase_orders'],
            query: fn () => Warehouse::query()->withCount('bins'),
            row: fn (Warehouse $w) => [
                'id' => $w->id,
                'codigo' => $w->code,
                'nombre' => $w->name,
                'predeterminado' => F::yesNo($w->is_default),
                'maneja_ubicaciones' => F::yesNo($w->uses_bins),
                'ubicaciones' => $w->bins_count,
                'estado' => F::status($w->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function units(): ContiResource
    {
        return new ContiResource(
            key: 'unidades-medida',
            label: 'Unidades de medida',
            description: 'Las unidades de medida y con cuántos decimales se digitan.',
            screens: ['inventory.units', 'inventory.items'],
            query: fn () => UnitOfMeasure::query(),
            row: fn (UnitOfMeasure $u) => [
                'id' => $u->id,
                'codigo' => $u->code,
                'nombre' => $u->name,
                'decimales' => $u->decimals,
                'estado' => F::status($u->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function priceLists(): ContiResource
    {
        return new ContiResource(
            key: 'listas-precios',
            label: 'Listas de precios',
            description: 'Las listas de precios: moneda, vigencia, si incluyen IVA y cuál es la predeterminada. Los precios de cada artículo están en «precios».',
            screens: ['inventory.price_lists', 'billing.new_invoice', 'billing.sales_orders', 'business_partners.partners'],
            query: fn () => PriceList::query()->with('currency:id,code')->withCount('lines'),
            row: fn (PriceList $l) => [
                'id' => $l->id,
                'codigo' => $l->code,
                'nombre' => $l->name,
                'moneda' => $l->currency?->code,
                'incluye_iva' => F::yesNo($l->prices_include_tax),
                'vigente_desde' => F::date($l->valid_from),
                'vigente_hasta' => F::date($l->valid_to),
                'predeterminada' => F::yesNo($l->is_default),
                'articulos_con_precio' => $l->lines_count,
                'estado' => F::status($l->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function prices(): ContiResource
    {
        return new ContiResource(
            key: 'precios',
            label: 'Precios por lista',
            description: 'El precio de cada artículo en cada lista de precios. Si un artículo no aparece en la lista de un cliente, no tiene precio sugerido.',
            screens: ['inventory.price_lists', 'billing.new_invoice', 'billing.sales_orders'],
            query: fn () => PriceListItem::query()->whereHas('priceList')->whereHas('item')
                ->with(['priceList:id,code,name,currency_id,prices_include_tax', 'priceList.currency:id,code', 'item:id,code,name,avg_cost_local']),
            row: fn (PriceListItem $p) => [
                'lista' => F::codeName($p->priceList),
                'articulo' => F::codeName($p->item),
                'precio' => F::money($p->unit_price),
                'moneda' => $p->priceList?->currency?->code,
                'incluye_iva' => F::yesNo($p->priceList?->prices_include_tax),
                'costo_promedio_local' => self::seesCosts() ? F::money($p->item?->avg_cost_local) : null,
            ],
            search: fn (Builder $q, string $term) => $q->whereHas('item', fn (Builder $i) => $i->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")),
            filters: [
                'lista' => ['código de la lista', ContiFilters::relatedCode('priceList')],
                'articulo' => ['código del artículo', ContiFilters::relatedCode('item')],
            ],
            orderBy: ['price_list_id' => 'asc', 'item_id' => 'asc'],
            codeColumn: null,
        );
    }

    private static function lots(): ContiResource
    {
        return new ContiResource(
            key: 'lotes',
            label: 'Lotes',
            description: 'Los lotes de cada artículo, su vencimiento y la existencia que les queda.',
            screens: ['inventory.items', 'inventory.lot_expiry'],
            query: fn () => ItemLot::query()->whereHas('item')->with('item:id,code,name')->withSum('stockLevels', 'on_hand'),
            row: fn (ItemLot $l) => [
                'id' => $l->id,
                'articulo' => F::codeName($l->item),
                'lote' => $l->code,
                'vence' => F::date($l->expires_at),
                'existencia' => F::number($l->stock_levels_sum_on_hand ?? 0),
                'estado' => F::status($l->status, ['active' => 'activo', 'held' => 'retenido']),
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('code', 'like', "%{$term}%")
                ->orWhereHas('item', fn (Builder $i) => $i->where('code', $term)->orWhere('name', 'like', "%{$term}%"))),
            filters: ['articulo' => ['código del artículo', ContiFilters::relatedCode('item')]],
            dateColumn: 'expires_at',
            orderBy: ['expires_at' => 'asc'],
            codeColumn: null,
        );
    }

    private static function serials(): ContiResource
    {
        return new ContiResource(
            key: 'series',
            label: 'Números de serie',
            description: 'Las series de los artículos que se controlan por unidad: estado, almacén y garantía.',
            screens: ['inventory.items'],
            query: fn () => ItemSerial::query()->whereHas('item')->with(['item:id,code,name', 'warehouse:id,code,name']),
            row: fn (ItemSerial $s) => [
                'id' => $s->id,
                'articulo' => F::codeName($s->item),
                'serie' => $s->serial_number,
                'estado' => ItemSerial::STATUSES[$s->status] ?? $s->status,
                'almacen' => F::codeName($s->warehouse),
                'garantia_hasta' => F::date($s->warranty_until),
                'recibida' => F::date($s->received_at),
                'entregada' => F::date($s->issued_at),
            ],
            search: ['serial_number'],
            filters: [
                'articulo' => ['código del artículo', ContiFilters::relatedCode('item')],
                'estado' => [ContiFilters::options(ItemSerial::STATUSES), ContiFilters::oneOf('status', ItemSerial::STATUSES)],
            ],
            orderBy: ['serial_number' => 'asc'],
            codeColumn: null,
        );
    }

    private static function movements(): ContiResource
    {
        $row = fn (InventoryDocument $d) => [
            'id' => $d->id,
            'operacion' => InventoryDocument::OPERATIONS[$d->operation] ?? $d->operation,
            'tipo_documento' => F::codeName($d->documentType),
            'fecha_documento' => F::date($d->document_date),
            'fecha_contabilizacion' => F::date($d->posting_date),
            'socio' => F::codeName($d->businessPartner),
            'descripcion' => $d->description,
            'importacion' => F::yesNo($d->is_import),
            'asiento' => $d->journalEntry ? AccountingResources::entryLabel($d->journalEntry) : null,
            'facturada' => $d->operation === 'purchase_receipt' ? F::yesNo($d->invoice_journal_entry_id !== null) : null,
            'estado' => F::status($d->status),
        ];

        return new ContiResource(
            key: 'movimientos-inventario',
            label: 'Movimientos de inventario',
            description: 'Entradas, salidas, ajustes, traslados, entradas por compra, emisiones y recibos de producción. El detalle trae las líneas con artículo, almacén, cantidad y costo.',
            screens: ['inventory.movements', 'inventory.transfers', 'inventory.supplier_invoices'],
            query: fn () => InventoryDocument::query()->with([
                'documentType:id,code,name', 'businessPartner:id,code,name', 'journalEntry:id,document_type_id,document_number', 'journalEntry.documentType:id,code',
            ]),
            row: $row,
            detail: fn (InventoryDocument $d) => $row($d) + [
                'dua' => $d->is_import ? [
                    'numero' => $d->customs_declaration,
                    'aduana' => $d->customs_office,
                    'fecha' => F::date($d->customs_date),
                    'documento_transporte' => $d->transport_document,
                    'pais_origen' => $d->origin_country,
                ] : null,
                'lineas' => $d->lines()->with(['item:id,code,name', 'warehouse:id,code,name', 'toWarehouse:id,code,name'])->orderBy('line_number')->get()->map(fn ($l) => [
                    'articulo' => F::codeName($l->item),
                    'almacen' => F::codeName($l->warehouse),
                    'almacen_destino' => F::codeName($l->toWarehouse),
                    'cantidad' => F::number($l->quantity),
                    'costo_unitario_local' => self::seesCosts() ? F::money($l->unit_cost_local) : null,
                    'descripcion' => $l->description,
                ])->all(),
            ],
            search: ['description'],
            filters: [
                'operacion' => [ContiFilters::options(InventoryDocument::OPERATIONS), ContiFilters::oneOf('operation', InventoryDocument::OPERATIONS)],
                'socio' => ['código del socio', ContiFilters::relatedCode('businessPartner')],
                'articulo' => ['código de un artículo en las líneas', fn (Builder $q, string $v) => $q->whereHas('lines.item', fn (Builder $i) => $i->where('code', $v))],
                'pendientes_de_facturar' => ['sí: entradas por compra sin factura de proveedor', fn (Builder $q, string $v) => ContiFilters::truthy($v)
                    ? $q->where('operation', 'purchase_receipt')->where('status', 'posted')->whereNull('invoice_journal_entry_id') : $q],
            ],
            dateColumn: 'posting_date',
            orderBy: ['posting_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function kardex(): ContiResource
    {
        return new ContiResource(
            key: 'kardex',
            label: 'Kardex',
            description: 'Cada entrada y salida de cada artículo con su costo y el saldo después del movimiento. Conviene filtrar por artículo.',
            screens: ['inventory.items'],
            query: fn () => StockJournal::query()->with(['item:id,code,name', 'warehouse:id,code,name', 'journalEntry:id,document_type_id,document_number', 'journalEntry.documentType:id,code']),
            row: fn (StockJournal $s) => [
                'fecha' => F::date($s->posting_date),
                'articulo' => F::codeName($s->item),
                'almacen' => F::codeName($s->warehouse),
                'movimiento' => match ($s->direction) {
                    'in' => 'entrada',
                    'out' => 'salida',
                    'revaluation' => 'revaluación de costo',
                    default => $s->direction,
                },
                'cantidad' => F::number($s->quantity),
                'costo_unitario_local' => F::money($s->unit_cost_local),
                'costo_total_local' => F::money($s->total_cost_local),
                'saldo_cantidad' => F::number($s->balance_quantity),
                'costo_promedio_despues' => F::money($s->avg_cost_local_after),
                'asiento' => $s->journalEntry ? AccountingResources::entryLabel($s->journalEntry) : null,
            ],
            filters: [
                'articulo' => ['código del artículo', ContiFilters::relatedCode('item')],
                'almacen' => ['código del almacén', ContiFilters::relatedCode('warehouse')],
            ],
            dateColumn: 'posting_date',
            orderBy: ['posting_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function purchaseOrders(): ContiResource
    {
        $row = fn (PurchaseOrder $o) => [
            'id' => $o->id,
            'numero' => $o->number,
            'proveedor' => F::codeName($o->businessPartner),
            'fecha' => F::date($o->order_date),
            'fecha_esperada' => F::date($o->expected_date),
            'descripcion' => $o->description,
            'estado' => PurchaseOrder::STATUSES[$o->status] ?? $o->status,
        ];

        return new ContiResource(
            key: 'ordenes-compra',
            label: 'Órdenes de compra',
            description: 'Las órdenes de compra a proveedores y su avance. El detalle trae lo ordenado, lo recibido y lo pendiente por línea.',
            screens: ['inventory.purchase_orders'],
            query: fn () => PurchaseOrder::query()->with('businessPartner:id,code,name'),
            row: $row,
            detail: fn (PurchaseOrder $o) => $row($o) + [
                'lineas' => $o->lines()->with(['item:id,code,name', 'warehouse:id,code,name'])->orderBy('line_number')->get()->map(fn ($l) => [
                    'articulo' => F::codeName($l->item),
                    'almacen' => F::codeName($l->warehouse),
                    'ordenado' => F::number($l->quantity),
                    'recibido' => F::number($l->quantity_received),
                    'pendiente' => F::number(bcsub((string) $l->quantity, (string) $l->quantity_received, 6)),
                    'costo_pactado' => F::money($l->unit_cost_local),
                ])->all(),
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('number', $term)->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('businessPartner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"))),
            filters: [
                'proveedor' => ['código del proveedor', ContiFilters::relatedCode('businessPartner')],
                'estado' => [ContiFilters::options(PurchaseOrder::STATUSES), ContiFilters::oneOf('status', PurchaseOrder::STATUSES)],
            ],
            dateColumn: 'order_date',
            orderBy: ['order_date' => 'desc', 'id' => 'desc'],
            codeColumn: 'number',
        );
    }

    private static function stockCounts(): ContiResource
    {
        $row = fn (StockCount $c) => [
            'id' => $c->id,
            'numero' => $c->number,
            'corte' => F::date($c->cutoff_date),
            'almacen' => F::codeName($c->warehouse),
            'familia' => F::codeName($c->itemGroup),
            'a_ciegas' => F::yesNo($c->blind),
            'descripcion' => $c->description,
            'estado' => StockCount::STATUSES[$c->status] ?? $c->status,
        ];

        return new ContiResource(
            key: 'tomas-fisicas',
            label: 'Tomas físicas',
            description: 'Los conteos físicos de inventario. El detalle compara la existencia del sistema con lo contado.',
            screens: ['inventory.stock_counts'],
            query: fn () => StockCount::query()->with(['warehouse:id,code,name', 'itemGroup:id,code,name']),
            row: $row,
            detail: fn (StockCount $c) => $row($c) + [
                'lineas' => $c->lines()->with('item:id,code,name')->orderBy('line_number')->get()->map(fn ($l) => [
                    'articulo' => F::codeName($l->item),
                    'sistema' => F::number($l->theoretical_quantity),
                    'contado' => F::number($l->counted_quantity),
                    'diferencia' => $l->counted_quantity === null ? null : F::number(bcsub((string) $l->counted_quantity, (string) $l->theoretical_quantity, 6)),
                ])->all(),
            ],
            filters: ['estado' => [ContiFilters::options(StockCount::STATUSES), ContiFilters::oneOf('status', StockCount::STATUSES)]],
            dateColumn: 'cutoff_date',
            orderBy: ['cutoff_date' => 'desc'],
            codeColumn: 'number',
        );
    }

    private static function writeDowns(): ContiResource
    {
        $row = fn (InventoryWriteDown $w) => [
            'id' => $w->id,
            'corte' => F::date($w->as_of),
            'descripcion' => $w->description,
            'asiento' => $w->journalEntry ? AccountingResources::entryLabel($w->journalEntry) : null,
            'estado' => F::status($w->status),
        ];

        return new ContiResource(
            key: 'deterioros',
            label: 'Deterioro de inventario (NIC 2)',
            description: 'Los avalúos de valor neto realizable. El detalle trae costo, VNR y lo contabilizado por artículo.',
            screens: ['inventory.write_downs'],
            query: fn () => InventoryWriteDown::query()->with('journalEntry.documentType:id,code'),
            row: $row,
            detail: fn (InventoryWriteDown $w) => $row($w) + [
                'lineas' => $w->lines()->with('item:id,code,name')->orderBy('line_number')->get()->map(fn ($l) => [
                    'articulo' => F::codeName($l->item),
                    'cantidad' => F::number($l->quantity),
                    'costo_total' => F::money($l->cost_value_local),
                    'vnr_total' => F::money($l->nrv_value_local),
                    'contabilizado' => F::money($l->movement_local),
                    'motivo' => $l->reason,
                ])->all(),
            ],
            dateColumn: 'as_of',
            orderBy: ['as_of' => 'desc'],
            codeColumn: null,
        );
    }

    private static function productionOrders(): ContiResource
    {
        return new ContiResource(
            key: 'ordenes-fabricacion',
            label: 'Órdenes de fabricación',
            description: 'Las órdenes de producción: producto, cantidad planificada y producida, y estado.',
            screens: ['inventory.production_orders'],
            query: fn () => ProductionOrder::query()->with(['item:id,code,name', 'warehouse:id,code,name']),
            row: fn (ProductionOrder $o) => [
                'id' => $o->id,
                'producto' => F::codeName($o->item),
                'almacen' => F::codeName($o->warehouse),
                'receta' => $o->bill_of_material_id ? F::codeName(BillOfMaterial::find($o->bill_of_material_id)) : 'sin receta',
                'planificado' => F::number($o->planned_quantity),
                'producido' => F::number($o->produced_quantity),
                'fecha' => F::date($o->order_date),
                'descripcion' => $o->description,
                'estado' => self::PRODUCTION_STATUSES[$o->status] ?? $o->status,
            ],
            filters: [
                'producto' => ['código del artículo', ContiFilters::relatedCode('item')],
                'estado' => [ContiFilters::options(self::PRODUCTION_STATUSES), ContiFilters::oneOf('status', self::PRODUCTION_STATUSES)],
            ],
            dateColumn: 'order_date',
            orderBy: ['order_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function billsOfMaterials(): ContiResource
    {
        $row = fn (BillOfMaterial $b) => [
            'id' => $b->id,
            'codigo' => $b->code,
            'nombre' => $b->name,
            'producto' => F::codeName($b->item),
            'rinde' => F::number($b->output_quantity),
            'predeterminada' => F::yesNo($b->is_default),
            'estado' => F::status($b->status),
        ];

        return new ContiResource(
            key: 'listas-materiales',
            label: 'Listas de materiales (recetas)',
            description: 'Las recetas de producción. El detalle trae los componentes con su cantidad por lote y su merma.',
            screens: ['inventory.bills_of_materials', 'inventory.production_orders'],
            query: fn () => BillOfMaterial::query()->with('item:id,code,name'),
            row: $row,
            detail: fn (BillOfMaterial $b) => $row($b) + [
                'notas' => $b->notes,
                'componentes' => $b->lines()->with(['componentItem:id,code,name', 'warehouse:id,code,name'])->get()->map(fn ($l) => [
                    'componente' => F::codeName($l->componentItem),
                    'cantidad_por_lote' => F::number($l->quantity),
                    'merma_porcentaje' => F::number($l->scrap_percentage, 2),
                    'almacen' => F::codeName($l->warehouse),
                ])->all(),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function landedCosts(): ContiResource
    {
        return new ContiResource(
            key: 'costos-importacion',
            label: 'Costos de importación',
            description: 'Costos (flete, aranceles…) aplicados sobre una entrada de mercancía, con lo capitalizado al inventario y lo llevado a resultados.',
            screens: ['inventory.landed_costs'],
            query: fn () => LandedCostDocument::query()->with(['businessPartner:id,code,name', 'receipt:id,document_date,description']),
            row: fn (LandedCostDocument $d) => [
                'id' => $d->id,
                'proveedor' => F::codeName($d->businessPartner),
                'entrada' => $d->receipt ? '#'.$d->receipt->id.' del '.F::date($d->receipt->document_date) : null,
                'fecha' => F::date($d->posting_date),
                'monto' => F::money($d->amount),
                'capitalizado' => F::money($d->capitalized_amount),
                'a_resultados' => F::money($d->expensed_amount),
                'descripcion' => $d->description,
                'estado' => F::status($d->status),
            ],
            search: ['description'],
            dateColumn: 'posting_date',
            orderBy: ['posting_date' => 'desc'],
            codeColumn: null,
        );
    }

    private static function importCosts(): ContiResource
    {
        return new ContiResource(
            key: 'rubros-nacionalizacion',
            label: 'Rubros de nacionalización',
            description: 'Facturas de proveedores de nacionalización contabilizadas contra la transitoria, y cuánto falta asignar al costo de las importaciones.',
            screens: ['inventory.import_costs'],
            query: fn () => ImportCostDocument::query()->with('businessPartner:id,code,name'),
            row: fn (ImportCostDocument $d) => [
                'id' => $d->id,
                'numero' => $d->number,
                'proveedor' => F::codeName($d->businessPartner),
                'rubro' => $d->concept,
                'fecha' => F::date($d->document_date),
                'vencimiento' => F::date($d->due_date),
                'monto' => F::money($d->amount),
                'asignado' => F::money($d->allocated_amount),
                'por_asignar' => F::money(bcsub((string) $d->amount, (string) $d->allocated_amount, 2)),
                'estado' => ImportCostDocument::STATUSES[$d->status] ?? $d->status,
            ],
            search: ['concept', 'description'],
            filters: ['estado' => [ContiFilters::options(ImportCostDocument::STATUSES), ContiFilters::oneOf('status', ImportCostDocument::STATUSES)]],
            dateColumn: 'document_date',
            orderBy: ['document_date' => 'desc'],
            codeColumn: 'number',
        );
    }

    private static function glDeterminations(): ContiResource
    {
        return new ContiResource(
            key: 'determinacion-cuentas',
            label: 'Determinación de cuentas de inventario',
            description: 'Qué cuenta usa cada categoría (inventario, costo de ventas, ajustes, ingresos…) por artículo, grupo, almacén o compañía. Gana lo más específico.',
            screens: ['inventory.gl_determinations'],
            query: fn () => GlDetermination::query()->with(['account:id,code,description_es', 'costAllocationRule:id,code,name']),
            row: fn (GlDetermination $g) => [
                'id' => $g->id,
                'alcance' => GlDetermination::SCOPE_LEVELS[$g->scope_level] ?? $g->scope_level,
                'aplica_a' => self::scopeName($g->scope_level, $g->scope_id),
                'categoria' => GlDetermination::CATEGORIES[$g->category] ?? $g->category,
                'cuenta' => $g->account ? "{$g->account->code} {$g->account->description_es}" : null,
                'norma_reparto' => F::codeName($g->costAllocationRule),
            ],
            filters: [
                'categoria' => [ContiFilters::options(GlDetermination::CATEGORIES), ContiFilters::oneOf('category', GlDetermination::CATEGORIES)],
                'alcance' => [ContiFilters::options(GlDetermination::SCOPE_LEVELS), ContiFilters::oneOf('scope_level', GlDetermination::SCOPE_LEVELS)],
            ],
            orderBy: ['category' => 'asc', 'scope_level' => 'asc'],
            codeColumn: null,
        );
    }

    private static function scopeName(?string $level, ?int $id): ?string
    {
        if ($id === null) {
            return $level === 'company' ? 'toda la compañía' : null;
        }

        return match ($level) {
            'item' => F::codeName(Item::find($id)),
            'item_group' => F::codeName(ItemGroup::find($id)),
            'warehouse' => F::codeName(Warehouse::find($id)),
            default => null,
        };
    }
}
