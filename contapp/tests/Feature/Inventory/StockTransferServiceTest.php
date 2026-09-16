<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\DataTransferObjects\StockTransferLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostStockTransferService;

function transferFixture(): array
{
    $f = inventoryFixture();

    $f['destination'] = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2']);

    // 100 u a ₡1.000 en el almacén de origen.
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 1000),
    ]);

    return $f;
}

function transfer(array $f, $quantity = 30, array $overrides = []): InventoryDocument
{
    return app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            itemId: $f['item']->id,
            fromWarehouseId: $overrides['from'] ?? $f['warehouse']->id,
            toWarehouseId: $overrides['to'] ?? $f['destination']->id,
            quantity: $quantity,
            fromWarehouseBinId: $overrides['fromBin'] ?? null,
            toWarehouseBinId: $overrides['toBin'] ?? null,
        )],
    );
}

it('mueve la existencia de un almacén a otro sin cambiar el total ni el costo', function () {
    $f = transferFixture();

    transfer($f, 30);

    $porAlmacen = ItemWarehouse::where('item_id', $f['item']->id)
        ->pluck('on_hand', 'warehouse_id');

    expect((float) $porAlmacen[$f['warehouse']->id])->toBe(70.0)
        ->and((float) $porAlmacen[$f['destination']->id])->toBe(30.0)
        // Ni la existencia total ni el valor cambian: es el mismo inventario.
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0);
});

it('LA PRUEBA CENTRAL: con la misma cuenta en ambos almacenes NO genera asiento', function () {
    $f = transferFixture();

    $asientosAntes = JournalEntry::count();
    $consecutivoAntes = $f['documentType']->fresh()->next_consecutive;

    $document = transfer($f, 30);

    // Un asiento Debe X / Haber X por el mismo monto no cambiaría nada y
    // consumiría un consecutivo del libro diario.
    expect($document->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe($asientosAntes)
        ->and($f['documentType']->fresh()->next_consecutive)->toBe($consecutivoAntes)
        // Pero el movimiento sí queda registrado en el kardex.
        ->and(StockJournal::where('inventory_document_line_id', $document->lines->first()->id)->count())->toBe(2);
});

it('con cuentas distintas por almacén SÍ reclasifica el valor entre ellas', function () {
    $f = transferFixture();

    $cuentaDestino = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id,
        'scope_level' => 'warehouse',
        'scope_id' => $f['destination']->id,
        'category' => 'inventory',
        'account_id' => $cuentaDestino->id,
    ]);

    $document = transfer($f, 30);
    $entry = JournalEntry::find($document->journal_entry_id);

    expect($document->journal_entry_id)->not->toBeNull()
        // 30 u × ₡1.000 = ₡30.000 reclasificados
        ->and((float) $entry->details->firstWhere('account_id', $cuentaDestino->id)->debit_local)->toBe(30000.0)
        ->and((float) $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id)->credit_local)->toBe(30000.0);
});

it('el asiento del traslado no toca resultados: el inventario total no cambia', function () {
    $f = transferFixture();

    $cuentaDestino = ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'account_type' => 'asset']);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'warehouse', 'scope_id' => $f['destination']->id,
        'category' => 'inventory', 'account_id' => $cuentaDestino->id,
    ]);

    $document = transfer($f, 30);

    $saldoOrigen = JournalDetail::where('account_id', $f['accounts']['inventory']->id)
        ->get()->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);
    $saldoDestino = JournalDetail::where('account_id', $cuentaDestino->id)
        ->get()->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // La entrada original dejó ₡100.000 en origen; tras trasladar ₡30.000
    // quedan ₡70.000 ahí y ₡30.000 en destino: el total sigue en ₡100.000.
    expect($saldoOrigen)->toBe(70000.0)
        ->and($saldoDestino)->toBe(30000.0)
        ->and($saldoOrigen + $saldoDestino)->toBe(100000.0);
});

it('deja dos filas de kardex: una salida del origen y una entrada al destino', function () {
    $f = transferFixture();

    $document = transfer($f, 30);
    $kardex = StockJournal::where('inventory_document_line_id', $document->lines->first()->id)->get();

    $salida = $kardex->firstWhere('direction', 'out');
    $entrada = $kardex->firstWhere('direction', 'in');

    expect($salida->warehouse_id)->toBe($f['warehouse']->id)
        ->and((float) $salida->balance_quantity)->toBe(70.0)
        ->and($entrada->warehouse_id)->toBe($f['destination']->id)
        ->and((float) $entrada->balance_quantity)->toBe(30.0)
        // El promedio se repite: un traslado no lo cambia.
        ->and((float) $salida->avg_cost_local_after)->toBe(1000.0)
        ->and((float) $entrada->avg_cost_local_after)->toBe(1000.0);
});

it('rechaza trasladar más de lo que hay en el almacén de origen', function () {
    $f = transferFixture();

    transfer($f, 101);
})->throws(InsufficientStockException::class);

it('rechaza un traslado con el mismo origen y destino', function () {
    $f = transferFixture();

    transfer($f, 10, ['to' => $f['warehouse']->id]);
})->throws(InvalidStockMovementException::class);

it('rechaza trasladar un artículo de servicio', function () {
    $f = transferFixture();
    $servicio = Item::factory()->service()->create([
        'company_id' => $f['company']->id, 'code' => 'SERV-1',
    ]);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            itemId: $servicio->id,
            fromWarehouseId: $f['warehouse']->id,
            toWarehouseId: $f['destination']->id,
            quantity: 1,
        )],
    );
})->throws(InvalidStockMovementException::class);

it('rechaza un almacén inactivo como destino', function () {
    $f = transferFixture();
    $f['destination']->update(['status' => 'inactive']);

    transfer($f, 10);
})->throws(InvalidStockMovementException::class);

// --- Con ubicaciones ---

it('traslada entre ubicaciones del mismo almacén', function () {
    $f = inventoryFixture();
    $f['warehouse']->update(['uses_bins' => true]);

    $binA = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);
    $binB = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'B-02', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 50, unitCostLocal: 1000, warehouseBinId: $binA->id),
    ]);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            itemId: $f['item']->id,
            fromWarehouseId: $f['warehouse']->id,
            toWarehouseId: $f['warehouse']->id,
            quantity: 20,
            fromWarehouseBinId: $binA->id,
            toWarehouseBinId: $binB->id,
        )],
    );

    $saldos = ItemBin::where('item_id', $f['item']->id)->pluck('on_hand', 'warehouse_bin_id');

    expect((float) $saldos[$binA->id])->toBe(30.0)
        ->and((float) $saldos[$binB->id])->toBe(20.0)
        // El almacén no cambió su total: la mercancía sigue ahí, en otro estante.
        ->and((float) $f['item']->fresh()->onHand())->toBe(50.0);
});

it('no permite trasladar desde una ubicación vacía aunque el almacén tenga existencia', function () {
    $f = inventoryFixture();
    $f['warehouse']->update(['uses_bins' => true]);
    $destino = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2']);

    $binA = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);
    $binB = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'B-02', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 50, unitCostLocal: 1000, warehouseBinId: $binA->id),
    ]);

    app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new StockTransferLineInput(
            itemId: $f['item']->id,
            fromWarehouseId: $f['warehouse']->id,
            toWarehouseId: $destino->id,
            quantity: 1,
            fromWarehouseBinId: $binB->id,
        )],
    );
})->throws(InsufficientStockException::class);

it('ATOMICIDAD: si una línea falla no queda documento, ni kardex, ni existencia movida', function () {
    $f = transferFixture();
    $documentosAntes = InventoryDocument::count();

    try {
        app(PostStockTransferService::class)->post(
            $f['company'], $f['documentType'], now(), now(),
            [
                new StockTransferLineInput(
                    itemId: $f['item']->id,
                    fromWarehouseId: $f['warehouse']->id,
                    toWarehouseId: $f['destination']->id,
                    quantity: 10,
                ),
                // Esta excede lo que queda y revierte también la primera.
                new StockTransferLineInput(
                    itemId: $f['item']->id,
                    fromWarehouseId: $f['warehouse']->id,
                    toWarehouseId: $f['destination']->id,
                    quantity: 200,
                ),
            ],
        );
        $this->fail('Debió lanzar InsufficientStockException.');
    } catch (InsufficientStockException) {
        // esperado
    }

    $porAlmacen = ItemWarehouse::where('item_id', $f['item']->id)
        ->pluck('on_hand', 'warehouse_id');

    expect(InventoryDocument::count())->toBe($documentosAntes)
        ->and((float) $porAlmacen[$f['warehouse']->id])->toBe(100.0)
        ->and(StockJournal::where('direction', 'in')->where('warehouse_id', $f['destination']->id)->count())->toBe(0);
});

it('un documento con varias líneas las traslada todas y las deja en el mismo documento', function () {
    $f = transferFixture();
    $tercero = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM3']);

    $document = app(PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [
            new StockTransferLineInput(
                itemId: $f['item']->id, fromWarehouseId: $f['warehouse']->id,
                toWarehouseId: $f['destination']->id, quantity: 20,
            ),
            new StockTransferLineInput(
                itemId: $f['item']->id, fromWarehouseId: $f['warehouse']->id,
                toWarehouseId: $tercero->id, quantity: 30,
            ),
        ],
    );

    $porAlmacen = ItemWarehouse::where('item_id', $f['item']->id)
        ->pluck('on_hand', 'warehouse_id');

    expect($document->lines)->toHaveCount(2)
        ->and((float) $porAlmacen[$f['warehouse']->id])->toBe(50.0)
        ->and((float) $porAlmacen[$f['destination']->id])->toBe(20.0)
        ->and((float) $porAlmacen[$tercero->id])->toBe(30.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});
