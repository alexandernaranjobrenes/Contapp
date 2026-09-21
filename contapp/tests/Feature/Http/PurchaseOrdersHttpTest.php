<?php

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\PurchaseOrder;

function purchaseOrderHttpFixture(): array
{
    $f = purchaseFixture();

    logInAsCompanyUser($f['company']);

    return $f;
}

function purchaseOrderPayload(array $f, $quantity = 100): array
{
    return [
        'business_partner_id' => $f['supplier']->id,
        'order_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => $quantity,
            'unit_cost_local' => 500,
        ]],
    ];
}

it('crea una orden de compra por HTTP y declara lo que viene en camino', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f, 100))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order = PurchaseOrder::sole();

    expect($order->status)->toBe('open')
        ->and((float) ItemWarehouse::where('item_id', $f['item']->id)->value('ordered'))->toBe(100.0);
});

it('el listado muestra las órdenes paginadas y filtra por estado', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f))->assertSessionHasNoErrors();

    $this->get(route('purchase-orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/PurchaseOrders/Index')
            ->has('orders.data', 1)
            ->where('orders.data.0.status', 'open')
        );

    $this->get(route('purchase-orders.index', ['status' => 'received']))
        ->assertInertia(fn ($page) => $page->has('orders.data', 0));
});

it('el detalle muestra lo ordenado, lo recibido y lo pendiente', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f, 100))->assertSessionHasNoErrors();

    $order = PurchaseOrder::sole();

    $this->get(route('purchase-orders.show', $order->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/PurchaseOrders/Show')
            ->has('lines', 1)
            ->where('lines.0.quantity', fn ($v) => (float) $v === 100.0)
            ->where('lines.0.pending', fn ($v) => (float) $v === 100.0)
            ->where('order.is_pending', true)
        );
});

it('rechaza un socio que no es proveedor, traducido a error de formulario', function () {
    $f = purchaseOrderHttpFixture();

    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'client',
    ]);

    $payload = purchaseOrderPayload($f);
    $payload['business_partner_id'] = $cliente->id;

    $this->post(route('purchase-orders.store'), $payload)->assertSessionHasErrors('lines');

    expect(PurchaseOrder::count())->toBe(0);
});

it('rechaza un artículo de otra compañía', function () {
    $f = purchaseOrderHttpFixture();

    $ajeno = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => App\Domains\Core\Models\Company::factory()->create()->id,
    ]);

    $payload = purchaseOrderPayload($f);
    $payload['lines'][0]['item_id'] = $ajeno->id;

    $this->post(route('purchase-orders.store'), $payload)->assertSessionHasErrors('lines.0.item_id');
});

it('rechaza una cantidad no positiva en la validación del formulario', function () {
    $f = purchaseOrderHttpFixture();

    $payload = purchaseOrderPayload($f);
    $payload['lines'][0]['quantity'] = 0;

    $this->post(route('purchase-orders.store'), $payload)->assertSessionHasErrors('lines.0.quantity');
});

it('cancela una orden sin recepciones', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f))->assertSessionHasNoErrors();
    $order = PurchaseOrder::sole();

    $this->post(route('purchase-orders.cancel', $order->id))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe('cancelled')
        ->and((float) ItemWarehouse::where('item_id', $f['item']->id)->value('ordered'))->toBe(0.0);
});

it('el formulario de movimientos ofrece solo las órdenes pendientes', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f))->assertSessionHasNoErrors();

    $this->get(route('inventory-movements.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('purchaseOrders', 1));

    $this->post(route('purchase-orders.cancel', PurchaseOrder::sole()->id))->assertSessionHasNoErrors();

    $this->get(route('inventory-movements.create'))
        ->assertInertia(fn ($page) => $page->has('purchaseOrders', 0));
});

it('una recepción enlazada por HTTP descarga el pendiente de la orden', function () {
    $f = purchaseOrderHttpFixture();

    $this->post(route('purchase-orders.store'), purchaseOrderPayload($f, 100))->assertSessionHasNoErrors();
    $order = PurchaseOrder::sole();

    $this->post(route('inventory-movements.store'), [
        'operation' => 'purchase_receipt',
        'document_type_id' => $f['documentType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'business_partner_id' => $f['supplier']->id,
        'purchase_order_id' => $order->id,
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 40,
            'unit_cost_local' => 500,
        ]],
    ])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe('partially_received')
        ->and((float) ItemWarehouse::where('item_id', $f['item']->id)->value('ordered'))->toBe(60.0);
});
