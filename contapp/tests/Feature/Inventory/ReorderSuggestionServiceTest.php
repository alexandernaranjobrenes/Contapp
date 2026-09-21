<?php

use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Services\PurchaseOrderService;
use App\Domains\Inventory\Services\ReorderSuggestionService;

function setLevels(array $f, $minimum, $maximum = null): void
{
    ItemWarehouse::updateOrCreate(
        ['item_id' => $f['item']->id, 'warehouse_id' => $f['warehouse']->id],
        ['minimum_stock' => $minimum, 'maximum_stock' => $maximum],
    );
}

function suggestions(array $f)
{
    return app(ReorderSuggestionService::class)->build($f['company']);
}

function receiveStock(array $f, $quantity): void
{
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: $quantity, unitCostLocal: 100),
    ]);
}

// ── Cuándo dispara ───────────────────────────────────────────────────────

it('sugiere comprar cuando lo disponible cae por debajo del mínimo', function () {
    $f = purchaseFixture();

    receiveStock($f, 5);
    setLevels($f, 10);

    $s = suggestions($f);

    expect($s)->toHaveCount(1)
        ->and((float) $s[0]['available'])->toBe(5.0)
        ->and((float) $s[0]['suggested_quantity'])->toBe(5.0);
});

it('estar exactamente en el mínimo sin máximo no deja nada que comprar', function () {
    $f = purchaseFixture();

    receiveStock($f, 10);
    setLevels($f, 10);

    // El disparo es <= mínimo, así que la fila entra al cálculo; pero el
    // objetivo es el propio mínimo y la cantidad sugerida da cero. La lista
    // es "qué comprar", y estar justo en el piso que uno fijó no es nada que
    // comprar. Emitir una fila con cantidad cero sería ruido.
    expect(suggestions($f))->toHaveCount(0);
});

it('estar exactamente en el mínimo CON máximo sí manda reponer hasta el máximo', function () {
    $f = purchaseFixture();

    receiveStock($f, 10);
    setLevels($f, 10, 40);

    // Acá el máximo sí define un objetivo por encima, y ese es todo el punto
    // de configurarlo: no esperar a bajar del piso para reponer.
    $s = suggestions($f);

    expect($s)->toHaveCount(1)
        ->and((float) $s[0]['suggested_quantity'])->toBe(30.0);
});

it('no sugiere nada por encima del mínimo', function () {
    $f = purchaseFixture();

    receiveStock($f, 20);
    setLevels($f, 10);

    expect(suggestions($f))->toHaveCount(0);
});

it('un mínimo en cero significa SIN control de reorden, no piso cero', function () {
    $f = purchaseFixture();

    // Sin existencia y sin mínimo configurado: no debe aparecer. Si el
    // filtro no estuviera, todo artículo agotado saldría como urgente.
    setLevels($f, 0);

    expect(suggestions($f))->toHaveCount(0);
});

// ── La fórmula, que es el corazón ────────────────────────────────────────

it('LA PRUEBA CENTRAL: lo apartado por un pedido NO cuenta como disponible', function () {
    $f = purchaseFixture();

    receiveStock($f, 20);
    setLevels($f, 10);

    // 20 en existencia, pero 15 apartadas para un cliente: solo quedan 5
    // libres para demanda nueva, así que hay que comprar aunque la
    // existencia se vea sana.
    ItemWarehouse::where('item_id', $f['item']->id)->update(['reserved' => 15]);

    $s = suggestions($f);

    expect($s)->toHaveCount(1)
        ->and((float) $s[0]['available'])->toBe(5.0);
});

it('LA OTRA PRUEBA CENTRAL: lo que viene en camino SÍ cuenta, y evita comprar dos veces', function () {
    $f = purchaseFixture();

    receiveStock($f, 5);
    setLevels($f, 10);

    // Sin la orden: falta comprar 5.
    expect(suggestions($f))->toHaveCount(1);

    // Con una orden abierta por 20, ya viene cubierto y no debe sugerir nada.
    app(PurchaseOrderService::class)->place(
        $f['company'], $f['supplier']->id, now(),
        [new PurchaseOrderLineInput($f['item']->id, $f['warehouse']->id, quantity: 20)],
    );

    expect(suggestions($f))->toHaveCount(0);
});

it('cancelar la orden vuelve a poner el artículo en la sugerencia', function () {
    $f = purchaseFixture();

    receiveStock($f, 5);
    setLevels($f, 10);

    $order = app(PurchaseOrderService::class)->place(
        $f['company'], $f['supplier']->id, now(),
        [new PurchaseOrderLineInput($f['item']->id, $f['warehouse']->id, quantity: 20)],
    );

    expect(suggestions($f))->toHaveCount(0);

    app(PurchaseOrderService::class)->cancel($order);

    expect(suggestions($f))->toHaveCount(1);
});

// ── Cuánto sugiere ───────────────────────────────────────────────────────

it('sin máximo repone solo hasta el mínimo', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);
    setLevels($f, 10);

    expect((float) suggestions($f)[0]['suggested_quantity'])->toBe(7.0);
});

it('con máximo repone hasta el máximo', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);
    setLevels($f, 10, 50);

    expect((float) suggestions($f)[0]['suggested_quantity'])->toBe(47.0);
});

it('un máximo por debajo de lo disponible no sugiere cantidad negativa', function () {
    $f = purchaseFixture();

    receiveStock($f, 8);
    // Configuración incoherente: máximo menor que el mínimo.
    setLevels($f, 10, 5);

    expect(suggestions($f))->toHaveCount(0);
});

it('estima el costo al promedio actual del artículo', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);   // entra a 100
    setLevels($f, 10);

    // Faltan 7 a un promedio de 100.
    expect((float) suggestions($f)[0]['estimated_cost'])->toBe(700.0);
});

// ── Alcance ──────────────────────────────────────────────────────────────

it('marca los artículos no comprables para que no se ordenen', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);
    setLevels($f, 10);
    $f['item']->update(['is_purchase_item' => false]);

    // Sigue apareciendo —está bajo mínimo y hay que hacer algo— pero
    // marcado: un artículo que se fabrica no va en una orden de compra.
    $s = suggestions($f);

    expect($s)->toHaveCount(1)
        ->and($s[0]['is_purchase_item'])->toBeFalse();
});

it('ignora artículos inactivos y de servicio', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);
    setLevels($f, 10);

    $f['item']->update(['status' => 'inactive']);
    expect(suggestions($f))->toHaveCount(0);

    $f['item']->update(['status' => 'active', 'is_inventory_item' => false]);
    expect(suggestions($f))->toHaveCount(0);
});

it('ordena lo más descubierto primero', function () {
    $f = purchaseFixture();

    $otro = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2', 'uom_id' => $f['item']->uom_id,
    ]);

    // ART-1 al 50% de su mínimo; ART-2 al 10%.
    receiveStock($f, 5);
    setLevels($f, 10);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($otro->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100),
    ]);

    ItemWarehouse::updateOrCreate(
        ['item_id' => $otro->id, 'warehouse_id' => $f['warehouse']->id],
        ['minimum_stock' => 10],
    );

    expect(collect(suggestions($f))->pluck('item_code')->all())->toBe(['ART-2', 'ART-1']);
});

it('aísla por compañía', function () {
    $f = purchaseFixture();

    receiveStock($f, 3);
    setLevels($f, 10);

    expect(suggestions(purchaseFixture()))->toHaveCount(0);
});
