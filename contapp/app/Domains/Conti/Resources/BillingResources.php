<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Conti\Support\ContiFormat as F;
use Illuminate\Database\Eloquent\Builder;

/** Facturación: pedidos, comprobantes y su configuración. */
final class BillingResources
{
    /** @return list<ContiResource> */
    public static function all(): array
    {
        return [
            self::salesOrders(),
            self::salesDocuments(),
            self::priceOverrides(),
            self::economicActivities(),
        ];
    }

    private static function salesOrders(): ContiResource
    {
        $row = fn (SalesOrder $o) => [
            'id' => $o->id,
            'numero' => $o->number,
            'cliente' => F::codeName($o->businessPartner),
            'fecha' => F::date($o->order_date),
            'entrega' => F::date($o->delivery_date),
            'descripcion' => $o->description,
            'estado' => SalesOrder::STATUSES[$o->status] ?? $o->status,
        ];

        return new ContiResource(
            key: 'ordenes-pedido',
            label: 'Órdenes de pedido',
            description: 'Los pedidos de clientes, que apartan mercancía sin generar asiento. El detalle trae lo pedido, lo facturado y lo que sigue apartado.',
            screens: ['billing.sales_orders'],
            query: fn () => SalesOrder::query()->with('businessPartner:id,code,name'),
            row: $row,
            detail: fn (SalesOrder $o) => $row($o) + [
                'lineas' => $o->lines()->with(['item:id,code,name', 'warehouse:id,code,name'])->orderBy('line_number')->get()->map(fn ($l) => [
                    'articulo' => F::codeName($l->item),
                    'bodega' => F::codeName($l->warehouse),
                    'pedido' => F::number($l->quantity),
                    'facturado' => F::number($l->quantity_invoiced),
                    'apartado' => $o->status === 'open' ? F::number(bcsub((string) $l->quantity, (string) $l->quantity_invoiced, 6)) : '0',
                    'precio_pactado' => F::money($l->unit_price),
                ])->all(),
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('number', $term)->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('businessPartner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"))),
            filters: [
                'cliente' => ['código del cliente', ContiFilters::relatedCode('businessPartner')],
                'estado' => [ContiFilters::options(SalesOrder::STATUSES), ContiFilters::oneOf('status', SalesOrder::STATUSES)],
            ],
            dateColumn: 'order_date',
            orderBy: ['order_date' => 'desc', 'id' => 'desc'],
            codeColumn: 'number',
        );
    }

    private static function salesDocuments(): ContiResource
    {
        $row = fn (SalesDocument $d) => [
            'id' => $d->id,
            'tipo' => FiscalCatalogs::DOCUMENT_TYPES[$d->fiscal_document_type] ?? $d->fiscal_document_type,
            'consecutivo' => $d->consecutive,
            'cliente' => $d->businessPartner ? F::codeName($d->businessPartner) : 'Consumidor final',
            'fecha_emision' => F::date($d->document_date),
            'fecha_contabilizacion' => F::date($d->posting_date),
            'condicion_venta' => FiscalCatalogs::SALE_CONDITIONS[$d->sale_condition] ?? $d->sale_condition,
            'plazo_dias' => $d->credit_term_days,
            'vencimiento' => F::date($d->due_date),
            'moneda' => $d->currency?->code,
            'tipo_cambio' => F::number($d->exchange_rate, 6),
            'total_venta' => F::money($d->total_sale),
            'descuentos' => F::money($d->total_discounts),
            'impuesto' => F::money($d->total_tax),
            'total' => F::money($d->total_document),
            'estado' => F::status($d->status),
        ];

        return new ContiResource(
            key: 'comprobantes',
            label: 'Comprobantes electrónicos emitidos',
            description: 'Facturas, tiquetes, notas de crédito y débito emitidos. El detalle trae las líneas, las referencias y las formas de pago.',
            screens: ['billing.sales_documents'],
            query: fn () => SalesDocument::query()->with(['businessPartner:id,code,name', 'currency:id,code']),
            row: $row,
            detail: fn (SalesDocument $d) => $row($d) + [
                'clave' => $d->clave,
                'observaciones' => $d->notes,
                'lineas' => $d->lines()->orderBy('line_number')->get()->map(fn ($l) => [
                    'codigo' => $l->item_code,
                    'descripcion' => $l->description,
                    'cantidad' => F::number($l->quantity),
                    'precio' => F::money($l->unit_price),
                    'descuento' => F::money($l->discount_amount),
                    'subtotal' => F::money($l->subtotal),
                    'impuesto' => F::money($l->tax_amount),
                    'total_linea' => F::money($l->line_total),
                ])->all(),
                'referencias' => $d->references()->get()->map(fn ($r) => [
                    'tipo' => $r->document_type,
                    'numero' => $r->number,
                    'razon' => $r->reason,
                ])->all(),
                'pagos' => $d->payments()->get()->map(fn ($p) => [
                    'medio' => FiscalCatalogs::PAYMENT_METHODS[$p->method_code] ?? $p->method_code,
                    'monto' => F::money($p->amount),
                ])->all(),
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('consecutive', 'like', "%{$term}%")->orWhere('clave', $term)
                ->orWhereHas('businessPartner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%")->orWhere('code', $term))),
            filters: [
                'cliente' => ['código del cliente', ContiFilters::relatedCode('businessPartner')],
                'tipo' => [ContiFilters::options(FiscalCatalogs::DOCUMENT_TYPES), ContiFilters::oneOf('fiscal_document_type', FiscalCatalogs::DOCUMENT_TYPES)],
                'condicion_venta' => ['01 (contado), 02 (crédito)', ContiFilters::oneOf('sale_condition', FiscalCatalogs::SALE_CONDITIONS)],
            ],
            dateColumn: 'posting_date',
            orderBy: ['posting_date' => 'desc', 'id' => 'desc'],
            codeColumn: 'consecutive',
        );
    }

    private static function priceOverrides(): ContiResource
    {
        return new ContiResource(
            key: 'cambios-precio',
            label: 'Cambios de precio autorizados',
            description: 'Cada línea de pedido o factura que se apartó del precio de lista, con quién la autorizó y por qué.',
            screens: ['billing.price_overrides'],
            query: fn () => PriceOverrideAuthorization::query()->with([
                'salesDocument:id,consecutive', 'salesOrder:id,number', 'item:id,code,name', 'requestedBy:id,name', 'authorizedBy:id,name',
            ]),
            row: fn (PriceOverrideAuthorization $p) => [
                'fecha' => F::date($p->created_at),
                'documento' => $p->salesDocument ? 'Comprobante '.$p->salesDocument->consecutive : ($p->salesOrder ? 'Pedido '.$p->salesOrder->number : null),
                'articulo' => F::codeName($p->item),
                'lista' => $p->price_list_code,
                'precio_lista' => F::money($p->list_unit_price),
                'precio_aplicado' => F::money($p->invoiced_unit_price),
                'diferencia' => F::money($p->difference),
                'solicito' => $p->requestedBy?->name,
                'autorizo' => $p->authorizedBy?->name,
                'motivo' => $p->reason,
            ],
            filters: ['articulo' => ['código del artículo', ContiFilters::relatedCode('item')]],
            dateColumn: 'created_at',
            orderBy: ['created_at' => 'desc'],
            codeColumn: null,
        );
    }

    private static function economicActivities(): ContiResource
    {
        return new ContiResource(
            key: 'actividades-economicas',
            label: 'Actividades económicas',
            description: 'Las actividades económicas con las que factura la compañía y la cuenta de ingresos de cada una.',
            screens: ['billing.settings', 'billing.new_invoice'],
            query: fn () => CompanyEconomicActivity::query()->with('revenueAccount:id,code,description_es'),
            row: fn (CompanyEconomicActivity $a) => [
                'id' => $a->id,
                'codigo' => $a->code,
                'nombre' => $a->name,
                'cuenta_ingresos' => $a->revenueAccount ? "{$a->revenueAccount->code} {$a->revenueAccount->description_es}" : null,
                'predeterminada' => F::yesNo($a->is_default),
                'estado' => F::status($a->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }
}
