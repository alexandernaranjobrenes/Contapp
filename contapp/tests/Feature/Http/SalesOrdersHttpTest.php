<?php

use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\ItemWarehouse;

function salesOrderHttpFixture(): array
{
    $f = salesFixture();

    logInAsCompanyUser($f['company']);

    return $f;
}

function orderPayload(array $f, $quantity = 30, array $overrides = []): array
{
    return array_merge([
        'business_partner_id' => $f['customer']->id,
        'order_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => $quantity,
            'unit_price' => 2500,
        ]],
    ], $overrides);
}

function reservedHttp(array $f): float
{
    return (float) ItemWarehouse::where('item_id', $f['item']->id)
        ->where('warehouse_id', $f['warehouse']->id)
        ->value('reserved');
}

it('registrar un pedido por HTTP aparta la mercancía sin contabilizar nada', function () {
    $f = salesOrderHttpFixture();

    $this->post(route('sales-orders.store'), orderPayload($f, 30))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(reservedHttp($f))->toBe(30.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and(SalesOrder::sole()->status)->toBe('open');
});

it('la pantalla de alta muestra cuánto hay libre de cada artículo', function () {
    $f = salesOrderHttpFixture();

    $this->post(route('sales-orders.store'), orderPayload($f, 40))->assertSessionHasNoErrors();

    $this->get(route('sales-orders.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/SalesOrders/Create')
            ->where('stock.0.on_hand', fn ($v) => (float) $v === 100.0)
            ->where('stock.0.reserved', fn ($v) => (float) $v === 40.0)
            ->where('stock.0.free', fn ($v) => (float) $v === 60.0)
        );
});

it('traduce a error de formulario el intento de apartar más de lo disponible', function () {
    $f = salesOrderHttpFixture();

    $this->post(route('sales-orders.store'), orderPayload($f, 150))
        ->assertSessionHasErrors('order');

    expect(SalesOrder::count())->toBe(0)
        ->and(reservedHttp($f))->toBe(0.0);
});

it('el listado muestra el pedido con lo que sigue pendiente', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $this->get(route('sales-orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/SalesOrders/Index')
            ->has('orders', 1)
            ->where('orders.0.status', 'open')
            ->where('orders.0.pending', fn ($v) => (float) $v === 30.0)
        );
});

it('el detalle del pedido muestra lo pedido, lo facturado y lo pendiente', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();

    $this->get(route('sales-orders.show', $order->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/SalesOrders/Show')
            ->where('lines.0.quantity', fn ($v) => (float) $v === 30.0)
            ->where('lines.0.quantity_invoiced', fn ($v) => (float) $v === 0.0)
            ->where('lines.0.pending', fn ($v) => (float) $v === 30.0)
            ->has('invoices', 0)
        );
});

it('cancelar por HTTP libera la reserva', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();

    $this->post(route('sales-orders.cancel', $order->id), ['reason' => 'El cliente desistió'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(reservedHttp($f))->toBe(0.0)
        ->and($order->fresh()->status)->toBe('cancelled');
});

it('traduce a error de formulario el intento de cancelar dos veces', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();

    $this->post(route('sales-orders.cancel', $order->id))->assertSessionHasNoErrors();
    $this->post(route('sales-orders.cancel', $order->id))->assertSessionHasErrors('order');
});

it('el detalle enlaza las facturas emitidas contra el pedido', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();
    postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);

    $this->get(route('sales-orders.show', $order->id))
        ->assertInertia(fn ($page) => $page
            ->has('invoices', 1)
            ->where('order.status', 'invoiced')
            ->where('lines.0.pending', fn ($v) => (float) $v === 0.0)
        );
});

// --- "Copiar a" ---

it('el "Copiar a" del pedido precarga la factura con lo pendiente', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();

    $this->get(route('sales-documents.create', ['order' => $order->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sourceOrder.id', $order->id)
            ->where('sourceOrder.business_partner_id', $f['customer']->id)
            ->has('sourceOrder.lines', 1)
            ->where('sourceOrder.lines.0.quantity', fn ($v) => (float) $v === 30.0)
            ->where('sourceDocument', null)
        );
});

it('el "Copiar a" del pedido solo ofrece lo que falta entregar', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();
    postSale($f, ['lines' => [saleLine($f, quantity: 18)], 'orderId' => $order->id]);

    $this->get(route('sales-documents.create', ['order' => $order->id]))
        ->assertInertia(fn ($page) => $page
            ->where('sourceOrder.lines.0.quantity', fn ($v) => (float) $v === 12.0)
        );
});

it('un pedido ya cumplido no precarga nada', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();
    postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);

    $this->get(route('sales-documents.create', ['order' => $order->id]))
        ->assertInertia(fn ($page) => $page->where('sourceOrder', null));
});

it('el "Copiar a" de la factura precarga la nota de crédito con lo devolvible', function () {
    $f = salesOrderHttpFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $this->get(route('sales-documents.create', ['correct' => $venta->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sourceDocument.id', $venta->id)
            ->where('sourceDocument.clave', $venta->clave)
            ->has('sourceDocument.lines', 1)
            ->where('sourceDocument.lines.0.quantity', fn ($v) => (float) $v === 10.0)
        );
});

it('el "Copiar a" descuenta lo que notas anteriores ya devolvieron', function () {
    $f = salesOrderHttpFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    postSale($f, [
        'fiscalType' => '03',
        'originalId' => $venta->id,
        'lines' => [saleLine($f, quantity: 4)],
        'references' => [[
            'document_type' => '01', 'number' => $venta->clave,
            'reason_code' => '01', 'reason' => 'Devolución parcial',
        ]],
    ]);

    $this->get(route('sales-documents.create', ['correct' => $venta->id]))
        ->assertInertia(fn ($page) => $page
            ->where('sourceDocument.lines.0.quantity', fn ($v) => (float) $v === 6.0)
        );
});

it('una nota de crédito no se puede corregir con otra nota', function () {
    $f = salesOrderHttpFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = postSale($f, [
        'fiscalType' => '03',
        'originalId' => $venta->id,
        'lines' => [saleLine($f, quantity: 4)],
        'references' => [[
            'document_type' => '01', 'number' => $venta->clave,
            'reason_code' => '01', 'reason' => 'Devolución parcial',
        ]],
    ]);

    $this->get(route('sales-documents.create', ['correct' => $nota->id]))
        ->assertInertia(fn ($page) => $page->where('sourceDocument', null));
});

it('facturar por HTTP contra un pedido libera su reserva', function () {
    $f = salesOrderHttpFixture();
    $this->post(route('sales-orders.store'), orderPayload($f, 30))->assertSessionHasNoErrors();

    $order = SalesOrder::sole();

    // El controlador exige un tipo de documento del módulo de ventas; el del
    // fixture es de inventario, que es lo que usa el servicio directamente.
    $tipoVentas = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FEV', 'origin_module' => 'ventas',
    ]);

    $this->post(route('sales-documents.store'), [
        'document_type_id' => $tipoVentas->id,
        'fiscal_document_type' => '01',
        'business_partner_id' => $f['customer']->id,
        'currency_id' => $f['company']->local_currency_id,
        'sale_condition' => '02',
        'credit_term_days' => 30,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'sales_order_id' => $order->id,
        'lines' => [[
            'cabys_code' => '2310110000000',
            'description' => 'Producto de prueba',
            'unit_code' => 'Unid',
            'quantity' => 30,
            'unit_price' => 2500,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(reservedHttp($f))->toBe(0.0)
        ->and($order->fresh()->status)->toBe('invoiced')
        ->and((float) $f['item']->fresh()->onHand())->toBe(70.0);
});
