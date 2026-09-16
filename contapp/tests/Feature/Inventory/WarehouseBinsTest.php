<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;

function binFixture(): array
{
    $f = inventoryFixture();

    $f['warehouse']->update(['uses_bins' => true]);

    $f['binA'] = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);
    $f['binB'] = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'B-02', 'status' => 'active']);

    return $f;
}

function receiveIntoBin(array $f, $bin, $quantity = 10, $unitCost = 1000)
{
    return postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: $quantity, unitCostLocal: $unitCost, warehouseBinId: $bin->id,
        ),
    ]);
}

it('una entrada con ubicación deja existencia en la ubicación y en el almacén', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10);

    expect((float) ItemBin::where('warehouse_bin_id', $f['binA']->id)->sole()->on_hand)->toBe(10.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(10.0);
});

it('la suma de las ubicaciones siempre iguala la existencia del almacén', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10);
    receiveIntoBin($f, $f['binB'], 15);

    $porUbicacion = ItemBin::where('item_id', $f['item']->id)->sum('on_hand');

    expect((float) $porUbicacion)->toBe(25.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(25.0);
});

it('LA PRUEBA CENTRAL: no se puede sacar de una ubicación vacía aunque el almacén tenga existencia', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10);

    // El almacén tiene 10, pero están en A-01, no en B-02.
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, warehouseBinId: $f['binB']->id),
    ]);
})->throws(InsufficientStockException::class);

it('la salida descuenta de la ubicación indicada', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10);
    receiveIntoBin($f, $f['binB'], 10);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 4, warehouseBinId: $f['binA']->id),
    ]);

    $saldos = ItemBin::where('item_id', $f['item']->id)->pluck('on_hand', 'warehouse_bin_id');

    expect((float) $saldos[$f['binA']->id])->toBe(6.0)
        ->and((float) $saldos[$f['binB']->id])->toBe(10.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(16.0);
});

it('el conteo físico cuenta la ubicación, no el almacén entero', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10);
    receiveIntoBin($f, $f['binB'], 10);

    // Se cuenta A-01 y aparecen 8, no 20.
    postMovement($f, 'count_adjustment', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 8, warehouseBinId: $f['binA']->id),
    ]);

    $saldos = ItemBin::where('item_id', $f['item']->id)->pluck('on_hand', 'warehouse_bin_id');

    expect((float) $saldos[$f['binA']->id])->toBe(8.0)
        ->and((float) $saldos[$f['binB']->id])->toBe(10.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(18.0);
});

it('el kardex registra en qué ubicación ocurrió el movimiento', function () {
    $f = binFixture();

    $document = receiveIntoBin($f, $f['binA'], 10);

    expect(StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole()->warehouse_bin_id)
        ->toBe($f['binA']->id);
});

it('exige ubicación cuando el almacén las maneja', function () {
    $f = binFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100),
    ]);
})->throws(InvalidStockMovementException::class);

it('rechaza una ubicación cuando el almacén no las maneja', function () {
    $f = binFixture();

    $simple = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'SIMPLE', 'uses_bins' => false,
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $simple->id,
            quantity: 1, unitCostLocal: 100, warehouseBinId: $f['binA']->id,
        ),
    ]);
})->throws(InvalidStockMovementException::class);

it('rechaza una ubicación de otro almacén', function () {
    $f = binFixture();

    $otro = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2', 'uses_bins' => true,
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $otro->id,
            quantity: 1, unitCostLocal: 100, warehouseBinId: $f['binA']->id,
        ),
    ]);
})->throws(InvalidStockMovementException::class);

it('rechaza una ubicación inactiva', function () {
    $f = binFixture();
    $f['binA']->update(['status' => 'inactive']);

    receiveIntoBin($f, $f['binA'], 1);
})->throws(InvalidStockMovementException::class);

it('un almacén sin ubicaciones sigue funcionando exactamente como antes', function () {
    $f = inventoryFixture();

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    expect((float) $f['item']->fresh()->onHand())->toBe(10.0)
        ->and(StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole()->warehouse_bin_id)->toBeNull()
        ->and(ItemBin::count())->toBe(0);
});

it('las ubicaciones no afectan el costo promedio, que sigue siendo global', function () {
    $f = binFixture();

    receiveIntoBin($f, $f['binA'], 10, 1000);
    receiveIntoBin($f, $f['binB'], 10, 3000);

    // (10×1000 + 10×3000) / 20 = 2000, sin importar dónde esté cada unidad.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(2000.0);
});
