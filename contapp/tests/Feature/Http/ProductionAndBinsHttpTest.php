<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;

function productionHttpFixture(): array
{
    $f = movementFixture();

    foreach (['wip', 'production_variance'] as $category) {
        $f['accounts'][$category] = ChartOfAccount::factory()->create([
            'company_id' => $f['company']->id,
            'account_type' => $category === 'wip' ? 'asset' : 'cost_of_sales',
        ]);

        GlDetermination::factory()->create([
            'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
            'category' => $category, 'account_id' => $f['accounts'][$category]->id,
        ]);
    }

    // Existencia de materia prima.
    test()->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasNoErrors();

    $f['product'] = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'PT-1']);

    return $f;
}

function createOrderHttp(array $f): ProductionOrder
{
    test()->post(route('production-orders.store'), [
        'item_id' => $f['product']->id,
        'warehouse_id' => $f['warehouse']->id,
        'planned_quantity' => 5,
        'order_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    return ProductionOrder::where('company_id', $f['company']->id)->latest('id')->first();
}

it('crea una orden de fabricación y la lista con su saldo en proceso', function () {
    $f = productionHttpFixture();
    createOrderHttp($f);

    $this->get(route('production-orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Production/Index')
            ->has('orders', 1)
            ->where('orders.0.status', 'open')
            ->where('orders.0.wip_balance', fn ($value) => (float) $value === 0.0)
        );
});

it('emitir y recibir por HTTP deja la orden con el WIP en cero', function () {
    $f = productionHttpFixture();
    $order = createOrderHttp($f);

    $this->post(route('production-orders.issue', $order->id), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 5,
        ]],
    ])->assertSessionHasNoErrors();

    expect((float) $order->fresh()->wipBalance())->toBe(5000.0);

    $this->post(route('production-orders.receive', $order->id), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'quantity' => 5,
    ])->assertSessionHasNoErrors();

    expect((float) $order->fresh()->wipBalance())->toBe(0.0)
        ->and((float) $f['product']->fresh()->avg_cost_local)->toBe(1000.0);
});

it('traduce a error de formulario recibir producto sin costo acumulado', function () {
    $f = productionHttpFixture();
    $order = createOrderHttp($f);

    $this->post(route('production-orders.receive', $order->id), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'quantity' => 5,
    ])->assertSessionHasErrors('production');
});

it('cerrar por HTTP una orden con saldo la manda a desviación', function () {
    $f = productionHttpFixture();
    $order = createOrderHttp($f);

    $this->post(route('production-orders.issue', $order->id), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 5,
        ]],
    ])->assertSessionHasNoErrors();

    $this->post(route('production-orders.close', $order->id), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('closed')
        ->and($order->fresh()->variance_journal_entry_id)->not->toBeNull();
});

// --- Ubicaciones ---

it('crea ubicaciones dentro de un almacén y rechaza el código duplicado', function () {
    $f = movementFixture();

    $payload = ['code' => 'A-01', 'name' => 'Pasillo A', 'status' => 'active'];

    $this->post(route('warehouse-bins.store', $f['warehouse']->id), $payload)->assertSessionHasNoErrors();
    $this->post(route('warehouse-bins.store', $f['warehouse']->id), $payload)->assertSessionHasErrors('code');

    expect(WarehouseBin::where('warehouse_id', $f['warehouse']->id)->count())->toBe(1);
});

it('la pantalla de ubicaciones muestra la existencia de cada una', function () {
    $f = movementFixture();
    $f['warehouse']->update(['uses_bins' => true]);

    $bin = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['warehouse_bin_id'] = $bin->id;

    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $this->get(route('warehouse-bins.index', $f['warehouse']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Warehouses/Bins')
            ->has('bins', 1)
            ->where('bins.0.on_hand', fn ($value) => (float) $value === 10.0)
        );
});

it('un movimiento en un almacén con ubicaciones las exige, traducido a error de formulario', function () {
    $f = movementFixture();
    $f['warehouse']->update(['uses_bins' => true]);

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasErrors('lines');
});

it('rechaza eliminar una ubicación con existencias', function () {
    $f = movementFixture();
    $f['warehouse']->update(['uses_bins' => true]);

    $bin = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['warehouse_bin_id'] = $bin->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $this->delete(route('warehouse-bins.destroy', [$f['warehouse']->id, $bin->id]))
        ->assertSessionHasErrors('bin');

    expect(WarehouseBin::find($bin->id))->not->toBeNull();
});

it('elimina una ubicación vacía', function () {
    $f = movementFixture();
    $bin = WarehouseBin::create(['warehouse_id' => $f['warehouse']->id, 'code' => 'A-01', 'status' => 'active']);

    $this->delete(route('warehouse-bins.destroy', [$f['warehouse']->id, $bin->id]))->assertSessionHasNoErrors();

    expect(WarehouseBin::find($bin->id))->toBeNull()
        ->and(ItemBin::count())->toBe(0);
});

// --- Traslados entre almacenes ---

it('registra un traslado por HTTP y mueve la existencia', function () {
    $f = movementFixture();
    $destino = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2',
    ]);

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasNoErrors();

    $this->post(route('stock-transfers.store'), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'from_warehouse_id' => $f['warehouse']->id,
            'to_warehouse_id' => $destino->id,
            'quantity' => 4,
        ]],
    ])->assertSessionHasNoErrors();

    $saldos = ItemWarehouse::where('item_id', $f['item']->id)
        ->pluck('on_hand', 'warehouse_id');

    expect((float) $saldos[$f['warehouse']->id])->toBe(6.0)
        ->and((float) $saldos[$destino->id])->toBe(4.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(10.0);
});

it('traduce a error de formulario un traslado sin existencia suficiente', function () {
    $f = movementFixture();
    $destino = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2',
    ]);

    $this->post(route('stock-transfers.store'), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'from_warehouse_id' => $f['warehouse']->id,
            'to_warehouse_id' => $destino->id,
            'quantity' => 5,
        ]],
    ])->assertSessionHasErrors('transfer');

    expect(InventoryDocument::where('operation', 'transfer')->count())->toBe(0);
});

it('la pantalla lista los traslados con su origen y destino', function () {
    $f = movementFixture();
    $destino = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2',
    ]);

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'));

    $this->post(route('stock-transfers.store'), [
        'document_type_id' => $f['documentType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'item_id' => $f['item']->id,
            'from_warehouse_id' => $f['warehouse']->id,
            'to_warehouse_id' => $destino->id,
            'quantity' => 4,
        ]],
    ]);

    $this->get(route('stock-transfers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Transfers/Index')
            ->has('transfers', 1)
            ->where('transfers.0.lines.0.to_warehouse.code', 'ALM2')
            // El traslado sí deja asiento —misma cuenta al debe y al haber,
            // por trazabilidad— aunque no cambie ningún saldo.
            ->where('transfers.0.journal_entry_id', fn ($v) => $v !== null)
        );
});
