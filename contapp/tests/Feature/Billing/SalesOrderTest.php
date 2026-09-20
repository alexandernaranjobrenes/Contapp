<?php

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Billing\DataTransferObjects\SalesOrderLineInput;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Exceptions\InvalidSalesOrderException;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Services\SalesOrderService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\DataTransferObjects\StockTransferLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PostStockTransferService;

function placeOrder(array $f, $quantity = 30, array $overrides = []): SalesOrder
{
    return app(SalesOrderService::class)->place(
        company: $f['company'],
        businessPartnerId: $overrides['partnerId'] ?? $f['customer']->id,
        orderDate: now(),
        lines: $overrides['lines'] ?? [new SalesOrderLineInput(
            itemId: $overrides['itemId'] ?? $f['item']->id,
            warehouseId: $f['warehouse']->id,
            quantity: $quantity,
            unitPrice: 2500,
        )],
    );
}

function reservedOf(array $f): float
{
    return (float) ItemWarehouse::where('item_id', $f['item']->id)
        ->where('warehouse_id', $f['warehouse']->id)
        ->value('reserved');
}

// --- La orden aparta, sin tocar contabilidad ---

it('la orden de pedido NO genera asiento ni movimiento de inventario', function () {
    $f = salesFixture();
    $asientosAntes = JournalEntry::count();

    placeOrder($f, 30);

    // La existencia no se mueve: la mercancía sigue en la bodega.
    expect(JournalEntry::count())->toBe($asientosAntes)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('LA CLAVE: la orden aparta mercancía y la deja de mostrar como disponible', function () {
    $f = salesFixture();

    placeOrder($f, 30);

    // Hay 100, pero 30 tienen dueño: solo 70 están libres.
    expect((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and(reservedOf($f))->toBe(30.0);
});

it('una venta a otro cliente no puede llevarse lo apartado', function () {
    $f = salesFixture();

    placeOrder($f, 90);

    // Quedan 10 libres de las 100; vender 20 debe rechazarse.
    postSale($f, ['lines' => [saleLine($f, quantity: 20)]]);
})->throws(InsufficientStockException::class);

it('el mensaje distingue "no hay" de "está apartado"', function () {
    $f = salesFixture();
    placeOrder($f, 90);

    try {
        postSale($f, ['lines' => [saleLine($f, quantity: 20)]]);
        $this->fail('Debió rechazar la venta.');
    } catch (InsufficientStockException $e) {
        expect($e->getMessage())->toContain('apartar');
    }
});

it('lo libre sí se puede vender', function () {
    $f = salesFixture();
    placeOrder($f, 90);

    postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    expect((float) $f['item']->fresh()->onHand())->toBe(90.0)
        ->and(reservedOf($f))->toBe(90.0);
});

it('rechaza apartar más de lo que hay libre', function () {
    $f = salesFixture();
    placeOrder($f, 80);

    placeOrder($f, 30);
})->throws(InvalidSalesOrderException::class);

it('rechaza una orden a un socio que no es cliente', function () {
    $f = salesFixture();

    $proveedor = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'P-009', 'type' => 'supplier',
        'gl_account_id' => $f['accounts']['receivable']->id,
    ]);

    placeOrder($f, 10, ['partnerId' => $proveedor->id]);
})->throws(InvalidSalesOrderException::class);

// --- Facturar la orden libera la reserva ---

it('facturar la orden completa libera la reserva y la marca facturada', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);

    expect(reservedOf($f))->toBe(0.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(70.0)
        ->and($order->fresh()->status)->toBe('invoiced');
});

it('facturar parcialmente deja la orden abierta con el resto apartado', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 12)], 'orderId' => $order->id]);

    expect(reservedOf($f))->toBe(18.0)
        ->and($order->fresh()->status)->toBe('open')
        ->and((float) $order->fresh()->lines->first()->quantity_invoiced)->toBe(12.0);
});

it('LA TRAMPA QUE EVITA: una orden puede facturarse aunque aparte TODA la existencia', function () {
    $f = salesFixture();

    // Aparta las 100 unidades: no queda nada libre.
    $order = placeOrder($f, 100);

    // Su propia factura debe poder salir; si la reserva no se liberara antes
    // de rebajar el stock, la orden se bloquearía a sí misma.
    postSale($f, ['lines' => [saleLine($f, quantity: 100)], 'orderId' => $order->id]);

    expect((float) $f['item']->fresh()->onHand())->toBe(0.0)
        ->and(reservedOf($f))->toBe(0.0);
});

it('rechaza facturar más de lo pedido en la orden', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 40)], 'orderId' => $order->id]);
})->throws(InvalidSalesOrderException::class);

it('rechaza facturar la orden de otro cliente', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    $otro = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'C-002', 'type' => 'client',
        'identification_type' => '02', 'gl_account_id' => $f['accounts']['receivable']->id,
    ]);

    postSale($f, ['lines' => [saleLine($f, quantity: 10)], 'orderId' => $order->id, 'partnerId' => $otro->id]);
})->throws(InvalidSalesDocumentException::class);

it('rechaza facturar dos veces una orden ya cumplida', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);
    postSale($f, ['lines' => [saleLine($f, quantity: 1)], 'orderId' => $order->id]);
})->throws(InvalidSalesOrderException::class);

it('la factura queda enlazada a la orden que cumple', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    $factura = postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);

    expect($factura->fresh()->sales_order_id)->toBe($order->id);
});

// --- Cancelar ---

it('cancelar la orden devuelve la mercancía a disponible', function () {
    $f = salesFixture();
    $order = placeOrder($f, 40);

    expect(reservedOf($f))->toBe(40.0);

    app(SalesOrderService::class)->cancel($f['company'], $order, 'El cliente desistió');

    expect(reservedOf($f))->toBe(0.0)
        ->and($order->fresh()->status)->toBe('cancelled')
        // Nada se movió del inventario: solo se soltó la promesa.
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('cancelar una orden a medio facturar suelta solo lo que quedaba', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 10)], 'orderId' => $order->id]);
    app(SalesOrderService::class)->cancel($f['company'], $order);

    expect(reservedOf($f))->toBe(0.0)
        // Las 10 facturadas salieron de verdad y no vuelven.
        ->and((float) $f['item']->fresh()->onHand())->toBe(90.0);
});

it('rechaza cancelar una orden ya facturada', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    postSale($f, ['lines' => [saleLine($f, quantity: 30)], 'orderId' => $order->id]);
    app(SalesOrderService::class)->cancel($f['company'], $order->fresh());
})->throws(InvalidSalesOrderException::class);

it('ATOMICIDAD: si la factura falla la reserva no se pierde', function () {
    $f = salesFixture();
    $order = placeOrder($f, 30);

    try {
        // Sin plazo de crédito la venta a crédito se rechaza, ya después de
        // haber consumido la orden dentro de la transacción.
        postSale($f, ['lines' => [saleLine($f, quantity: 10)], 'orderId' => $order->id, 'creditTermDays' => null]);
        $this->fail('Debió rechazar la venta sin plazo.');
    } catch (InvalidSalesDocumentException) {
        // esperado
    }

    expect(reservedOf($f))->toBe(30.0)
        ->and($order->fresh()->status)->toBe('open');
});

it('los números de orden son consecutivos por compañía', function () {
    $f = salesFixture();

    $primera = placeOrder($f, 10);
    $segunda = placeOrder($f, 10);

    expect($primera->number)->toBe('00000001')
        ->and($segunda->number)->toBe('00000002');
});

it('un traslado tampoco puede llevarse lo apartado a otro almacén', function () {
    $f = salesFixture();
    placeOrder($f, 95);

    $otro = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM-2', 'status' => 'active',
    ]);

    // Quedan 5 libres de las 100; mover 20 al otro almacén dejaría al cliente
    // sin la mercancía que se le prometió en este.
    app(PostStockTransferService::class)->post(
        company: $f['company'],
        documentType: $f['documentType'],
        documentDate: now(),
        postingDate: now(),
        lines: [new StockTransferLineInput(
            itemId: $f['item']->id,
            fromWarehouseId: $f['warehouse']->id,
            toWarehouseId: $otro->id,
            quantity: 20,
        )],
    );
})->throws(InsufficientStockException::class);
