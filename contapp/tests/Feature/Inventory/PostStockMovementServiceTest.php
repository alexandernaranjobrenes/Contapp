<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Exceptions\MissingGlDeterminationException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;

it('una entrada debita inventario, acredita el ajuste de aumento y fija el costo promedio', function () {
    $f = inventoryFixture('500.000000');

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 5000),
    ]);

    $entry = JournalEntry::find($document->journal_entry_id);
    $debit = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);
    $credit = $entry->details->firstWhere('account_id', $f['accounts']['stock_increase']->id);

    expect((float) $debit->debit_local)->toBe(500000.0)
        ->and((float) $debit->debit_foreign)->toBe(1000.0)
        ->and((float) $credit->credit_local)->toBe(500000.0)
        ->and((float) $credit->credit_foreign)->toBe(1000.0);

    $item = $f['item']->fresh();
    expect((float) $item->avg_cost_local)->toBe(5000.0)
        ->and((float) $item->avg_cost_foreign)->toBe(10.0)
        ->and((float) $item->onHand())->toBe(100.0);
});

it('promedia ponderadamente dos entradas a costos distintos', function () {
    $f = inventoryFixture('500.000000');

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 5000),
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 7000),
    ]);

    // (100*5000 + 100*7000) / 200 = 6000
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(6000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(200.0);
});

it('LA PRUEBA CENTRAL: una salida se valúa al TC congelado de la entrada, no al TC del día', function () {
    $f = inventoryFixture('500.000000');

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 5000),
    ]);

    // El colón se devalúa entre la compra y la venta.
    ExchangeRate::factory()->create([
        'company_id' => $f['company']->id,
        'currency_id' => $f['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $document = postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 40),
    ]);

    $entry = JournalEntry::find($document->journal_entry_id);
    $credit = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);

    // 40 u × ₡5.000 = ₡200.000. Al TC congelado (500) son $400 exactos.
    // Al TC del día (520) habrían salido $384,62 y el costo en dólares se
    // habría distorsionado solo.
    expect((float) $credit->credit_local)->toBe(200000.0)
        ->and((float) $credit->credit_foreign)->toBe(400.0);
});

it('la cuenta de inventario cierra en CERO en ambas monedas al agotarse la existencia', function () {
    $f = inventoryFixture('500.000000');

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 5000),
    ]);

    ExchangeRate::factory()->create([
        'company_id' => $f['company']->id,
        'currency_id' => $f['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '613.500000',
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 40),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 60),
    ]);

    $rows = JournalDetail::where('account_id', $f['accounts']['inventory']->id)->get();

    $saldoLocal = $rows->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);
    $saldoForeign = $rows->sum(fn ($r) => (float) $r->debit_foreign - (float) $r->credit_foreign);

    expect($saldoLocal)->toBe(0.0)
        ->and($saldoForeign)->toBe(0.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(0.0);
});

it('el kardex guarda exactamente el mismo monto que quedó en el asiento', function () {
    $f = inventoryFixture('613.500000');

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 7, unitCostLocal: 3333.33),
    ]);

    $kardex = StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole();
    $detail = JournalDetail::where('journal_entry_id', $document->journal_entry_id)
        ->where('account_id', $f['accounts']['inventory']->id)
        ->sole();

    expect((float) $kardex->total_cost_local)->toBe((float) $detail->debit_local)
        ->and((float) $kardex->total_cost_foreign)->toBe((float) $detail->debit_foreign);
});

it('rechaza una salida mayor a la existencia del almacén', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 11),
    ]);
})->throws(InsufficientStockException::class);

it('no permite sacar de un almacén usando existencia que está en otro', function () {
    $f = inventoryFixture();
    $otro = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $otro->id, quantity: 1),
    ]);
})->throws(InsufficientStockException::class);

it('un conteo por encima de la existencia la aumenta sin mover el costo promedio', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $document = postMovement($f, 'count_adjustment', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 12),
    ]);

    $kardex = StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole();

    expect($kardex->direction)->toBe('in')
        ->and((float) $kardex->quantity)->toBe(2.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(12.0);
});

it('un conteo por debajo de la existencia la disminuye contra la cuenta de ajuste', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $document = postMovement($f, 'count_adjustment', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 7),
    ]);

    $entry = JournalEntry::find($document->journal_entry_id);
    $debit = $entry->details->firstWhere('account_id', $f['accounts']['stock_decrease']->id);

    expect((float) $debit->debit_local)->toBe(3000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(7.0);
});

it('rechaza un conteo que coincide con la existencia: no habría nada que contabilizar', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'count_adjustment', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);
})->throws(InvalidStockMovementException::class);

it('rechaza mover un artículo de servicio, que no lleva kardex', function () {
    $f = inventoryFixture();
    $servicio = Item::factory()->service()->create(['company_id' => $f['company']->id, 'code' => 'SERV-1']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($servicio->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100),
    ]);
})->throws(InvalidStockMovementException::class);

it('rechaza una entrada con costo cero', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 5, unitCostLocal: 0),
    ]);
})->throws(InvalidStockMovementException::class);

it('ATOMICIDAD: sin cuenta configurada no queda ni documento, ni kardex, ni asiento, ni consecutivo consumido', function () {
    $f = inventoryFixture();
    GlDetermination::where('company_id', $f['company']->id)->where('category', 'stock_increase')->delete();

    $consecutivoAntes = $f['documentType']->fresh()->next_consecutive;

    try {
        postMovement($f, 'goods_receipt', [
            new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
        ]);
        $this->fail('Debió lanzar MissingGlDeterminationException.');
    } catch (MissingGlDeterminationException) {
        // esperado
    }

    expect(InventoryDocument::count())->toBe(0)
        ->and(StockJournal::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and(ItemWarehouse::count())->toBe(0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(0.0)
        ->and($f['documentType']->fresh()->next_consecutive)->toBe($consecutivoAntes);
});

it('la determinación por artículo gana sobre la de grupo, almacén y compañía', function () {
    $f = inventoryFixture();

    $group = ItemGroup::factory()->create(['company_id' => $f['company']->id]);
    $f['item']->update(['item_group_id' => $group->id]);

    $cuentaGrupo = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    $cuentaArticulo = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'item_group', 'scope_id' => $group->id,
        'category' => 'inventory', 'account_id' => $cuentaGrupo->id,
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'item', 'scope_id' => $f['item']->id,
        'category' => 'inventory', 'account_id' => $cuentaArticulo->id,
    ]);

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
    ]);

    $accountIds = JournalEntry::find($document->journal_entry_id)->details->pluck('account_id');

    expect($accountIds)->toContain($cuentaArticulo->id)
        ->and($accountIds)->not->toContain($cuentaGrupo->id)
        ->and($accountIds)->not->toContain($f['accounts']['inventory']->id);
});

it('cae a la cuenta por defecto del tipo de documento cuando la matriz no resuelve', function () {
    $f = inventoryFixture();
    GlDetermination::where('company_id', $f['company']->id)->where('category', 'stock_increase')->delete();

    $porDefecto = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    $f['documentType']->update(['default_credit_account_id' => $porDefecto->id]);

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
    ]);

    expect(JournalEntry::find($document->journal_entry_id)->details->pluck('account_id'))
        ->toContain($porDefecto->id);
});

it('un documento con varias líneas mantiene el kardex por almacén y el promedio global', function () {
    $f = inventoryFixture();
    $segundo = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2']);

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
        new StockLineInput($f['item']->id, $segundo->id, quantity: 10, unitCostLocal: 3000),
    ]);

    expect(StockJournal::where('journal_entry_id', $document->journal_entry_id)->count())->toBe(2)
        // (10*1000 + 10*3000) / 20 = 2000
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(2000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(20.0);

    $porAlmacen = ItemWarehouse::where('item_id', $f['item']->id)->pluck('on_hand', 'warehouse_id');

    expect((float) $porAlmacen[$f['warehouse']->id])->toBe(10.0)
        ->and((float) $porAlmacen[$segundo->id])->toBe(10.0);
});

it('funciona sin CurrentCompany ambiental, como lo haría un job en background', function () {
    $f = inventoryFixture();

    // Un comando de consola o un job en cola no tiene compañía activa; el
    // service recibe $company explícitamente y no debe depender del estado
    // ambiental para ser correcto (mismo criterio que PostJournalService).
    app(CurrentCompany::class)->clear();

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 4, unitCostLocal: 2500),
    ]);

    $kardex = StockJournal::withoutGlobalScope(CompanyScope::class)
        ->where('inventory_document_line_id', $document->lines->first()->id)
        ->sole();

    expect((float) $kardex->total_cost_local)->toBe(10000.0)
        ->and((float) $kardex->balance_quantity)->toBe(4.0);
});

it('el movimiento queda enlazado a su asiento y el asiento declara el módulo de origen', function () {
    $f = inventoryFixture();

    $document = postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
    ]);

    expect($document->journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::find($document->journal_entry_id)->source_module)->toBe('inventario')
        ->and($document->status)->toBe('posted');
});
