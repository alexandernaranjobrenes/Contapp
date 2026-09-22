<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Partidas abiertas de inventario: lo comprometido que todavía no se cumplió.
 *
 * ── Qué se entendió por "partida abierta" acá ────────────────────────────
 *
 * En cartera una partida abierta es una factura sin cobrar. En inventario el
 * equivalente es un COMPROMISO DE MERCANCÍA sin cumplir, y son dos:
 *
 *   - Pedidos de venta con saldo → mercancía prometida a un cliente
 *     (es lo que `reserved` aparta y deja de estar libre)
 *   - Órdenes de compra con saldo → mercancía prometida por un proveedor
 *     (es lo que `ordered` suma al disponible)
 *
 * Los dos en un solo reporte a propósito: la pregunta que se hace al mirarlo
 * es "¿voy a poder cumplir?", y esa se contesta comparando lo que se debe
 * entregar contra lo que está por llegar. En dos listas separadas hay que
 * hacer el cruce a mano.
 *
 * ── Los días abiertos son el dato que dispara la acción ──────────────────
 *
 * Un pedido de hace tres días es normal; el mismo pedido a los cuarenta es
 * un cliente esperando o una orden que el proveedor no despachó. Por eso la
 * lista se ordena por antigüedad y no por documento.
 */
class OpenItemsReport implements InventoryReport
{
    public function code(): string
    {
        return 'open-items';
    }

    public function label(): string
    {
        return 'Partidas abiertas';
    }

    public function description(): string
    {
        return 'Pedidos de venta y órdenes de compra con saldo pendiente, con los días que llevan abiertos.';
    }

    public function decision(): string
    {
        return 'Qué está comprometido y sin cumplir: a qué cliente le debemos mercancía y qué proveedor no ha despachado.';
    }

    public function group(): string
    {
        return 'Compromisos';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('kind', 'Origen', ReportFilter::SELECT, options: [
                'sales' => 'Solo pedidos de venta (lo que debemos entregar)',
                'purchase' => 'Solo órdenes de compra (lo que está por llegar)',
            ]),
            new ReportFilter('warehouse_id', 'Almacén', ReportFilter::SELECT, optionSource: 'warehouses'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('overdue_days', 'Abiertas hace más de (días)', ReportFilter::TEXT,
                hint: 'Deja solo lo que lleva demasiado tiempo sin cumplirse.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $today = now()->startOfDay();
        $rows = collect();

        if (($filters['kind'] ?? null) !== 'purchase') {
            $rows = $rows->merge($this->salesOrders($company, $filters, $today));
        }

        if (($filters['kind'] ?? null) !== 'sales') {
            $rows = $rows->merge($this->purchaseOrders($company, $filters, $today));
        }

        $minimumDays = filled($filters['overdue_days'] ?? null) ? (int) $filters['overdue_days'] : null;

        $rows = $rows
            ->when($minimumDays !== null, fn ($c) => $c->filter(fn (array $r) => $r['days_open'] >= $minimumDays))
            // Lo más viejo primero: es lo que hay que atender antes.
            ->sortByDesc('days_open')
            ->values()
            ->all();

        return new ReportResult(
            columns: [
                new ReportColumn('kind', 'Origen'),
                new ReportColumn('number', 'Documento'),
                new ReportColumn('document_date', 'Fecha', ReportColumn::DATE),
                new ReportColumn('partner', 'Cliente / Proveedor', width: 26),
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 26),
                new ReportColumn('warehouse_code', 'Almacén'),
                new ReportColumn('quantity', 'Comprometido', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('fulfilled', 'Cumplido', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('pending', 'Pendiente', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('days_open', 'Días abierta', ReportColumn::NUMBER),
                new ReportColumn('expected_date', 'Fecha esperada', ReportColumn::DATE),
            ],
            rows: $rows,
            notes: [
                'Un pedido de venta aparta mercancía y la resta del disponible; una orden de compra la suma.',
                'La lista viene ordenada de más antigua a más reciente: lo que lleva más tiempo abierto es lo que hay que atender.',
            ],
        );
    }

    /**
     * Mercancía prometida a un cliente y todavía sin facturar.
     */
    private function salesOrders(Company $company, array $filters, $today)
    {
        return DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->join('items', 'items.id', '=', 'sales_order_lines.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'sales_order_lines.warehouse_id')
            ->join('business_partners', 'business_partners.id', '=', 'sales_orders.business_partner_id')
            ->where('sales_orders.company_id', $company->id)
            ->where('sales_orders.status', 'open')
            ->whereColumn('sales_order_lines.quantity_invoiced', '<', 'sales_order_lines.quantity')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouses.id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->get([
                'sales_orders.number', 'sales_orders.order_date', 'sales_orders.delivery_date',
                'business_partners.name as partner',
                'items.code', 'items.name',
                'warehouses.code as warehouse_code',
                'sales_order_lines.quantity', 'sales_order_lines.quantity_invoiced',
            ])
            ->map(fn ($row) => [
                'kind' => 'Pedido de venta',
                'number' => $row->number,
                'document_date' => $row->order_date,
                'partner' => $row->partner,
                'code' => $row->code,
                'name' => $row->name,
                'warehouse_code' => $row->warehouse_code,
                'quantity' => (float) $row->quantity,
                'fulfilled' => (float) $row->quantity_invoiced,
                'pending' => (float) $row->quantity - (float) $row->quantity_invoiced,
                'days_open' => $today->diffInDays(\Carbon\Carbon::parse($row->order_date)),
                'expected_date' => $row->delivery_date,
            ]);
    }

    /**
     * Mercancía prometida por un proveedor y todavía sin recibir.
     */
    private function purchaseOrders(Company $company, array $filters, $today)
    {
        return DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->join('items', 'items.id', '=', 'purchase_order_lines.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'purchase_order_lines.warehouse_id')
            ->join('business_partners', 'business_partners.id', '=', 'purchase_orders.business_partner_id')
            ->where('purchase_orders.company_id', $company->id)
            ->whereIn('purchase_orders.status', ['open', 'partially_received'])
            ->whereColumn('purchase_order_lines.quantity_received', '<', 'purchase_order_lines.quantity')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouses.id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->get([
                'purchase_orders.number', 'purchase_orders.order_date', 'purchase_orders.expected_date',
                'business_partners.name as partner',
                'items.code', 'items.name',
                'warehouses.code as warehouse_code',
                'purchase_order_lines.quantity', 'purchase_order_lines.quantity_received',
            ])
            ->map(fn ($row) => [
                'kind' => 'Orden de compra',
                'number' => $row->number,
                'document_date' => $row->order_date,
                'partner' => $row->partner,
                'code' => $row->code,
                'name' => $row->name,
                'warehouse_code' => $row->warehouse_code,
                'quantity' => (float) $row->quantity,
                'fulfilled' => (float) $row->quantity_received,
                'pending' => (float) $row->quantity - (float) $row->quantity_received,
                'days_open' => $today->diffInDays(\Carbon\Carbon::parse($row->order_date)),
                'expected_date' => $row->expected_date,
            ]);
    }
}
