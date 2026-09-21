<?php

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseOrderException;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PurchaseOrderService;

function placePurchaseOrder(array $f, $quantity = 100, ?int $itemId = null)
{
    return app(PurchaseOrderService::class)->place(
        $f['company'],
        $f['supplier']->id,
        now(),
        [new PurchaseOrderLineInput($itemId ?? $f['item']->id, $f['warehouse']->id, quantity: $quantity, unitCostLocal: 500)],
    );
}

function purchaseOrderedOf(array $f): float
{
    return (float) (ItemWarehouse::where('item_id', $f['item']->id)
        ->where('warehouse_id', $f['warehouse']->id)
        ->value('ordered') ?? 0);
}

function receiveAgainstOrder(array $f, PurchaseOrder $order, $quantity)
{
    return app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: $quantity, unitCostLocal: 500)],
        businessPartnerId: $f['supplier']->id,
        purchaseOrderId: $order->id,
    );
}

// ── La orden no es un hecho económico ────────────────────────────────────

it('LA PRUEBA CENTRAL: una orden de compra no genera asiento', function () {
    $f = purchaseFixture();

    $antes = JournalEntry::count();

    placePurchaseOrder($f);

    expect(JournalEntry::count())->toBe($antes);
});

it('la orden suma a lo pendiente por recibir', function () {
    $f = purchaseFixture();

    placePurchaseOrder($f, 100);

    expect(purchaseOrderedOf($f))->toBe(100.0);
});

it('lo pendiente por recibir NO afecta la existencia ni lo disponible', function () {
    $f = purchaseFixture();

    placePurchaseOrder($f, 100);

    $stock = ItemWarehouse::where('item_id', $f['item']->id)->sole();

    expect((float) $stock->on_hand)->toBe(0.0)
        ->and((float) $stock->reserved)->toBe(0.0)
        ->and((float) $stock->ordered)->toBe(100.0);
});

it('LA OTRA PRUEBA CENTRAL: se puede ordenar sin tener existencia', function () {
    $f = purchaseFixture();

    // A diferencia de la reserva de un pedido de venta, que no puede
    // comprometer más de lo libre, una orden de compra no tiene tope: pedir
    // lo que no hay es exactamente para lo que sirve.
    $order = placePurchaseOrder($f, 9999);

    expect($order->status)->toBe('open')
        ->and(purchaseOrderedOf($f))->toBe(9999.0);
});

// ── Recepción ────────────────────────────────────────────────────────────

it('recibir descarga lo pendiente y deja la orden recibida', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 100);

    expect(purchaseOrderedOf($f))->toBe(0.0)
        ->and($order->refresh()->status)->toBe('received');
});

it('una recepción parcial deja el resto pendiente', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 40);

    expect(purchaseOrderedOf($f))->toBe(60.0)
        ->and($order->refresh()->status)->toBe('partially_received')
        ->and((float) $order->lines[0]->quantity_received)->toBe(40.0);
});

it('dos recepciones parciales cierran la orden', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 40);
    receiveAgainstOrder($f, $order, 60);

    expect(purchaseOrderedOf($f))->toBe(0.0)
        ->and($order->refresh()->status)->toBe('received');
});

it('recibir de más se topa a lo pendiente: en camino nunca queda negativo', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    // El proveedor mandó 120. La mercancía entra completa al kardex...
    receiveAgainstOrder($f, $order, 120);

    // ...pero la orden solo descarga sus 100.
    expect(purchaseOrderedOf($f))->toBe(0.0)
        ->and((float) $order->refresh()->lines[0]->quantity_received)->toBe(100.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(120.0);
});

it('la recepción sí mueve el kardex y contabiliza, como cualquier entrada por compra', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    $document = receiveAgainstOrder($f, $order, 100);

    expect($document->journal_entry_id)->not->toBeNull()
        ->and($document->purchase_order_id)->toBe($order->id)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('no se puede recibir contra una orden ya recibida', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 100);

    // La excepción viene de PurchaseOrderService, no del motor de stock: el
    // kardex no tiene nada que objetar, la orden sí.
    expect(fn () => receiveAgainstOrder($f, $order, 10))
        ->toThrow(InvalidPurchaseOrderException::class, 'ya no espera mercancía');
});

it('rechaza recibir contra una orden de otro proveedor', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    $otro = App\Domains\BusinessPartners\Models\BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'supplier',
    ]);

    expect(fn () => app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 500)],
        businessPartnerId: $otro->id,
        purchaseOrderId: $order->id,
    ))->toThrow(App\Domains\Inventory\Exceptions\InvalidStockMovementException::class, 'otro proveedor');
});

it('ATOMICIDAD: si la recepción falla, la orden no queda marcada como recibida', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    // Sin determinación de inventario el asiento revienta y todo se revierte.
    App\Domains\Inventory\Models\GlDetermination::where('category', 'inventory')->delete();

    try {
        receiveAgainstOrder($f, $order, 100);
    } catch (\Throwable) {
        // Se espera.
    }

    expect($order->refresh()->status)->toBe('open')
        ->and(purchaseOrderedOf($f))->toBe(100.0)
        ->and((float) $order->lines[0]->quantity_received)->toBe(0.0);
});

// ── Cancelar y cerrar ────────────────────────────────────────────────────

it('cancelar suelta lo pendiente', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    app(PurchaseOrderService::class)->cancel($order);

    expect(purchaseOrderedOf($f))->toBe(0.0)
        ->and($order->refresh()->status)->toBe('cancelled');
});

it('no se puede cancelar una orden que ya recibió mercancía: se cierra', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 40);

    expect(fn () => app(PurchaseOrderService::class)->cancel($order->refresh()))
        ->toThrow(InvalidPurchaseOrderException::class, 'no se puede cancelar');
});

it('cerrar con saldo suelta el resto y lo deja trazado', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    receiveAgainstOrder($f, $order, 40);
    app(PurchaseOrderService::class)->close($order->refresh());

    expect(purchaseOrderedOf($f))->toBe(0.0)
        ->and($order->refresh()->status)->toBe('closed')
        // Lo recibido queda registrado: cerrar no borra la historia.
        ->and((float) $order->lines[0]->quantity_received)->toBe(40.0);
});

it('no se puede cerrar dos veces', function () {
    $f = purchaseFixture();
    $order = placePurchaseOrder($f, 100);

    app(PurchaseOrderService::class)->close($order);

    expect(fn () => app(PurchaseOrderService::class)->close($order->refresh()))
        ->toThrow(InvalidPurchaseOrderException::class, 'ya está');
});

// ── Validaciones ─────────────────────────────────────────────────────────

it('rechaza un socio que no es proveedor', function () {
    $f = purchaseFixture();

    $cliente = App\Domains\BusinessPartners\Models\BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'client',
    ]);

    expect(fn () => app(PurchaseOrderService::class)->place(
        $f['company'], $cliente->id, now(),
        [new PurchaseOrderLineInput($f['item']->id, $f['warehouse']->id, quantity: 1)],
    ))->toThrow(InvalidPurchaseOrderException::class, 'no está registrado como proveedor');
});

it('rechaza ordenar un artículo de servicio', function () {
    $f = purchaseFixture();

    $f['item']->update(['is_inventory_item' => false]);

    expect(fn () => placePurchaseOrder($f, 10))
        ->toThrow(InvalidPurchaseOrderException::class, 'no lleva kardex');
});

it('rechaza ordenar un artículo no comprable', function () {
    $f = purchaseFixture();

    $f['item']->update(['is_purchase_item' => false]);

    expect(fn () => placePurchaseOrder($f, 10))
        ->toThrow(InvalidPurchaseOrderException::class, 'no está marcado como comprable');
});

it('rechaza una orden vacía y una cantidad no positiva', function () {
    $f = purchaseFixture();

    expect(fn () => app(PurchaseOrderService::class)->place($f['company'], $f['supplier']->id, now(), []))
        ->toThrow(InvalidPurchaseOrderException::class, 'al menos una línea');

    expect(fn () => new PurchaseOrderLineInput($f['item']->id, $f['warehouse']->id, quantity: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('numera las órdenes por compañía', function () {
    $f = purchaseFixture();

    expect(placePurchaseOrder($f, 1)->number)->toBe('00000001')
        ->and(placePurchaseOrder($f, 1)->number)->toBe('00000002');
});
