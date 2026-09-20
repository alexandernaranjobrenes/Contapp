<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\DataTransferObjects\StockTransferLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\ItemLotStock;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\ItemLotResolver;
use App\Domains\Inventory\Services\PostStockTransferService;

function lotFixture(): array
{
    $f = inventoryFixture();

    $f['item']->update(['tracks_lots' => true]);

    $f['lotA'] = ItemLot::create([
        'item_id' => $f['item']->id,
        'code' => 'L-2401',
        'expires_at' => now()->addMonths(6)->format('Y-m-d'),
        'status' => 'active',
    ]);

    $f['lotB'] = ItemLot::create([
        'item_id' => $f['item']->id,
        'code' => 'L-2402',
        'expires_at' => now()->addMonths(2)->format('Y-m-d'),
        'status' => 'active',
    ]);

    return $f;
}

function receiveIntoLot(array $f, ItemLot $lot, $quantity = 10, $unitCost = 1000, ?int $binId = null)
{
    return postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: $quantity, unitCostLocal: $unitCost,
            warehouseBinId: $binId, itemLotId: $lot->id,
        ),
    ]);
}

// ── Existencia por lote ──────────────────────────────────────────────────

it('una entrada con lote deja existencia en el lote y en el almacén', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);

    expect((float) ItemLotStock::where('item_lot_id', $f['lotA']->id)->sole()->on_hand)->toBe(10.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(10.0);
});

it('la suma de los lotes siempre iguala la existencia del almacén', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    receiveIntoLot($f, $f['lotB'], 15);

    $porLote = ItemLotStock::whereIn('item_lot_id', [$f['lotA']->id, $f['lotB']->id])->sum('on_hand');

    expect((float) $porLote)->toBe(25.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(25.0);
});

it('LA PRUEBA CENTRAL: no se puede sacar de un lote sin saldo aunque el almacén tenga existencia', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);

    // El almacén tiene 10, pero son del lote A, no del B.
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, itemLotId: $f['lotB']->id),
    ]);
})->throws(InsufficientStockException::class);

it('la salida descuenta del lote indicado', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    receiveIntoLot($f, $f['lotB'], 10);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 4, itemLotId: $f['lotA']->id),
    ]);

    $saldos = ItemLotStock::whereIn('item_lot_id', [$f['lotA']->id, $f['lotB']->id])
        ->pluck('on_hand', 'item_lot_id');

    expect((float) $saldos[$f['lotA']->id])->toBe(6.0)
        ->and((float) $saldos[$f['lotB']->id])->toBe(10.0);
});

it('el kardex registra en qué lote ocurrió el movimiento', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);

    expect(StockJournal::sole()->item_lot_id)->toBe($f['lotA']->id);
});

// ── Obligatoriedad ───────────────────────────────────────────────────────

it('exige lote cuando el artículo los maneja', function () {
    $f = lotFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 5, unitCostLocal: 1000),
    ]);
})->throws(InvalidStockMovementException::class, 'maneja lotes');

it('rechaza un lote cuando el artículo no los maneja', function () {
    $f = lotFixture();

    $f['item']->update(['tracks_lots' => false]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: 5, unitCostLocal: 1000, itemLotId: $f['lotA']->id,
        ),
    ]);
})->throws(InvalidStockMovementException::class, 'no maneja lotes');

it('rechaza un lote de otro artículo', function () {
    $f = lotFixture();

    $otro = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
        'tracks_lots' => true,
    ]);

    $lotAjeno = ItemLot::create(['item_id' => $otro->id, 'code' => 'X-01', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: 5, unitCostLocal: 1000, itemLotId: $lotAjeno->id,
        ),
    ]);
})->throws(InvalidStockMovementException::class, 'no pertenece al artículo');

// ── Vencimiento y retención ──────────────────────────────────────────────

it('no se puede vender un lote vencido', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    postMovement($f, 'sales_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, itemLotId: $f['lotA']->id),
    ]);
})->throws(InvalidStockMovementException::class, 'venció el');

it('no se puede consumir en producción un lote vencido', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    postMovement($f, 'production_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, itemLotId: $f['lotA']->id),
    ]);
})->throws(InvalidStockMovementException::class);

it('no se puede vender un lote retenido por calidad', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['status' => 'blocked']);

    postMovement($f, 'sales_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, itemLotId: $f['lotA']->id),
    ]);
})->throws(InvalidStockMovementException::class, 'está retenido');

it('LA OTRA PRUEBA CENTRAL: un lote vencido SÍ se puede dar de baja con una salida de mercancía', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    // Si esto fallara, la mercancía vencida quedaría atrapada en el
    // inventario para siempre, sin forma de sacarla del sistema.
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, itemLotId: $f['lotA']->id),
    ]);

    expect((float) ItemLotStock::where('item_lot_id', $f['lotA']->id)->sole()->on_hand)->toBe(0.0);
});

it('un lote retenido se puede ajustar por conteo', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['status' => 'blocked']);

    postMovement($f, 'count_adjustment', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 8, itemLotId: $f['lotA']->id),
    ]);

    expect((float) ItemLotStock::where('item_lot_id', $f['lotA']->id)->sole()->on_hand)->toBe(8.0);
});

it('el vencimiento se evalúa contra la fecha de contabilización, no contra hoy', function () {
    $f = lotFixture();

    // Vence mañana: un movimiento de hoy es válido...
    $f['lotA']->update(['expires_at' => now()->addDay()->format('Y-m-d')]);

    expect($f['lotA']->isExpiredOn(now()))->toBeFalse()
        // ...y uno contabilizado pasado mañana, no.
        ->and($f['lotA']->isExpiredOn(now()->addDays(2)))->toBeTrue();
});

it('un lote sin fecha de vencimiento nunca vence', function () {
    $f = lotFixture();

    $f['lotA']->update(['expires_at' => null]);

    expect($f['lotA']->isExpiredOn(now()->addYears(50)))->toBeFalse();
});

// ── FEFO ─────────────────────────────────────────────────────────────────

it('la sugerencia FEFO ordena por vencimiento más próximo', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);  // vence en 6 meses
    receiveIntoLot($f, $f['lotB'], 10);  // vence en 2 meses

    $sugerencia = app(ItemLotResolver::class)
        ->suggestForIssue($f['item']->fresh(), $f['warehouse']->id, null, now());

    expect($sugerencia->pluck('lot.code')->all())->toBe(['L-2402', 'L-2401']);
});

it('la sugerencia FEFO no ofrece lotes vencidos ni retenidos ni sin saldo', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    receiveIntoLot($f, $f['lotB'], 10);
    $f['lotB']->update(['status' => 'blocked']);

    $sinSaldo = ItemLot::create([
        'item_id' => $f['item']->id, 'code' => 'L-2403', 'status' => 'active',
        'expires_at' => now()->addDay()->format('Y-m-d'),
    ]);

    $sugerencia = app(ItemLotResolver::class)
        ->suggestForIssue($f['item']->fresh(), $f['warehouse']->id, null, now());

    expect($sugerencia->pluck('lot.code')->all())->toBe(['L-2401'])
        ->and($sugerencia->pluck('lot.code'))->not->toContain($sinSaldo->code);
});

it('los lotes sin vencimiento van al final del orden FEFO, no al principio', function () {
    $f = lotFixture();

    $sinFecha = ItemLot::create(['item_id' => $f['item']->id, 'code' => 'L-9999', 'status' => 'active']);

    receiveIntoLot($f, $f['lotA'], 10);
    receiveIntoLot($f, $f['lotB'], 10);
    receiveIntoLot($f, $sinFecha, 10);

    $sugerencia = app(ItemLotResolver::class)
        ->suggestForIssue($f['item']->fresh(), $f['warehouse']->id, null, now());

    expect($sugerencia->pluck('lot.code')->all())->toBe(['L-2402', 'L-2401', 'L-9999']);
});

// ── Interacción con lo ya construido ─────────────────────────────────────

it('los lotes no afectan el costo promedio, que sigue siendo global por artículo', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10, 1000);
    receiveIntoLot($f, $f['lotB'], 10, 2000);

    // 20 unidades, 30.000 de valor: el promedio es global y no distingue lotes.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1500.0);
});

it('una salida se valúa al promedio global, no al costo del lote del que sale', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10, 1000);
    receiveIntoLot($f, $f['lotB'], 10, 2000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, itemLotId: $f['lotA']->id),
    ]);

    // Sale del lote barato, pero se valúa a 1.500: identificación específica
    // sería otro motor de costeo (NIC 2 §23), no este.
    $salida = StockJournal::where('direction', 'out')->sole();

    expect((float) $salida->unit_cost_local)->toBe(1500.0);
});

it('un artículo sin lotes sigue funcionando exactamente como antes', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    expect((float) $f['item']->fresh()->onHand())->toBe(10.0)
        ->and(StockJournal::sole()->item_lot_id)->toBeNull()
        ->and(ItemLotStock::count())->toBe(0);
});

it('lotes y ubicaciones conviven: la existencia se desglosa por las dos dimensiones', function () {
    $f = lotFixture();

    $f['warehouse']->update(['uses_bins' => true]);
    $binA = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);
    $binB = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'B-02', 'status' => 'active']);

    receiveIntoLot($f, $f['lotA'], 10, 1000, $binA->id);
    receiveIntoLot($f, $f['lotA'], 5, 1000, $binB->id);

    $filas = ItemLotStock::where('item_lot_id', $f['lotA']->id)->get();

    expect($filas)->toHaveCount(2)
        ->and((float) $filas->sum('on_hand'))->toBe(15.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(15.0);
});

it('con ubicaciones, el lote se controla por ubicación: no se saca de la ubicación equivocada', function () {
    $f = lotFixture();

    $f['warehouse']->update(['uses_bins' => true]);
    $binA = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);
    $binB = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'B-02', 'status' => 'active']);

    receiveIntoLot($f, $f['lotA'], 10, 1000, $binA->id);

    postMovement($f, 'goods_issue', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: 1, warehouseBinId: $binB->id, itemLotId: $f['lotA']->id,
        ),
    ]);
})->throws(InsufficientStockException::class);

// ── Traslados ────────────────────────────────────────────────────────────

it('un traslado mueve el lote de almacén sin cambiarle la identidad', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);

    $destino = Warehouse::factory()->create(['company_id' => $f['company']->id, 'status' => 'active']);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            $f['item']->id, $f['warehouse']->id, $destino->id,
            quantity: 4, itemLotId: $f['lotA']->id,
        )],
    );

    $saldos = ItemLotStock::where('item_lot_id', $f['lotA']->id)->pluck('on_hand', 'warehouse_id');

    expect((float) $saldos[$f['warehouse']->id])->toBe(6.0)
        ->and((float) $saldos[$destino->id])->toBe(4.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(10.0);
});

it('un lote vencido SÍ se puede trasladar: es la vía para llevarlo a cuarentena', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);
    $f['lotA']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $cuarentena = Warehouse::factory()->create(['company_id' => $f['company']->id, 'status' => 'active']);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            $f['item']->id, $f['warehouse']->id, $cuarentena->id,
            quantity: 10, itemLotId: $f['lotA']->id,
        )],
    );

    expect((float) ItemLotStock::where('item_lot_id', $f['lotA']->id)
        ->where('warehouse_id', $cuarentena->id)->sole()->on_hand)->toBe(10.0);
});

it('las dos filas de kardex de un traslado llevan el mismo lote', function () {
    $f = lotFixture();

    receiveIntoLot($f, $f['lotA'], 10);

    $destino = Warehouse::factory()->create(['company_id' => $f['company']->id, 'status' => 'active']);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            $f['item']->id, $f['warehouse']->id, $destino->id,
            quantity: 4, itemLotId: $f['lotA']->id,
        )],
    );

    $filas = StockJournal::whereIn('direction', ['in', 'out'])
        ->whereNotNull('item_lot_id')
        ->where('quantity', '4.000000')
        ->get();

    expect($filas)->toHaveCount(2)
        ->and($filas->pluck('item_lot_id')->unique()->all())->toBe([$f['lotA']->id]);
});
