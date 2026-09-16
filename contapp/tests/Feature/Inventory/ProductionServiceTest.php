<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidProductionException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Exceptions\MissingGlDeterminationException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\PostProductionService;
use App\Domains\Inventory\Services\PostStockMovementService;

/**
 * Extiende inventoryFixture() con las dos cuentas de producción, una materia
 * prima con existencia y un producto terminado a fabricar.
 */
function productionFixture(): array
{
    $f = inventoryFixture();

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

    // $f['item'] es la materia prima; se le carga existencia.
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 1000),
    ]);

    $f['product'] = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'PT-1']);

    $f['order'] = ProductionOrder::factory()->create([
        'company_id' => $f['company']->id,
        'item_id' => $f['product']->id,
        'warehouse_id' => $f['warehouse']->id,
        'planned_quantity' => '10.000000',
    ]);

    return $f;
}

function issueToProduction(array $f, $quantity = 50)
{
    return app(PostProductionService::class)->issue(
        $f['company'], $f['documentType'], $f['order'], now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: $quantity)],
    );
}

function receiveProduction(array $f, $quantity = 10)
{
    return app(PostProductionService::class)->receive(
        $f['company'], $f['documentType'], $f['order'], $quantity, now(), now(),
    );
}

it('la emisión a producción saca materia prima y la traslada a WIP, no a gasto', function () {
    $f = productionFixture();

    $document = issueToProduction($f, 50);
    $entry = JournalEntry::find($document->journal_entry_id);

    $wip = $entry->details->firstWhere('account_id', $f['accounts']['wip']->id);
    $inventario = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);

    expect((float) $wip->debit_local)->toBe(50000.0)
        ->and((float) $inventario->credit_local)->toBe(50000.0)
        // No toca la cuenta de ajuste: esto no es una baja, es un traslado.
        ->and($entry->details->firstWhere('account_id', $f['accounts']['stock_decrease']->id))->toBeNull()
        ->and((float) $f['item']->fresh()->onHand())->toBe(50.0)
        ->and((float) $f['order']->wipBalance())->toBe(50000.0);
});

it('LA PRUEBA CENTRAL: el recibo descarga todo el WIP y lo deja en cero', function () {
    $f = productionFixture();

    issueToProduction($f, 50);
    $document = receiveProduction($f, 10);

    $entry = JournalEntry::find($document->journal_entry_id);
    $producto = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);

    // ₡50.000 de materia prima entre 10 unidades = ₡5.000 por unidad.
    expect((float) $producto->debit_local)->toBe(50000.0)
        ->and((float) $f['product']->fresh()->avg_cost_local)->toBe(5000.0)
        ->and((float) $f['product']->fresh()->onHand())->toBe(10.0)
        ->and((float) $f['order']->fresh()->wipBalance())->toBe(0.0);

    $saldoWip = JournalDetail::where('account_id', $f['accounts']['wip']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($saldoWip)->toBe(0.0);
});

it('el costo del producto terminado es el costo REAL consumido, no uno estimado', function () {
    $f = productionFixture();

    // Dos emisiones a costos distintos acumulan en la misma orden.
    issueToProduction($f, 30);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 2000),
    ]);

    issueToProduction($f, 20);

    // 30 × 1.000 = 30.000; después el promedio sube y 20 × 1.588,235...
    $wip = (float) $f['order']->wipBalance();
    receiveProduction($f, 10);

    expect((float) $f['product']->fresh()->avg_cost_local)->toBe(round($wip / 10, 6))
        ->and((float) $f['order']->fresh()->wipBalance())->toBe(0.0);
});

it('cerrar una orden con WIP sobrante lo manda a desviación de fabricación', function () {
    $f = productionFixture();

    issueToProduction($f, 50);

    // El lote se pierde: nunca ingresa producto terminado.
    $order = app(PostProductionService::class)->close(
        $f['company'], $f['documentType'], $f['order'], now(),
    );

    $entry = JournalEntry::find($order->variance_journal_entry_id);
    $desviacion = $entry->details->firstWhere('account_id', $f['accounts']['production_variance']->id);

    expect($order->status)->toBe('closed')
        ->and((float) $desviacion->debit_local)->toBe(50000.0);

    $saldoWip = JournalDetail::where('account_id', $f['accounts']['wip']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($saldoWip)->toBe(0.0);
});

it('cerrar una orden que ya descargó todo su WIP no genera asiento de desviación', function () {
    $f = productionFixture();

    issueToProduction($f, 50);
    receiveProduction($f, 10);

    $order = app(PostProductionService::class)->close(
        $f['company'], $f['documentType'], $f['order'], now(),
    );

    expect($order->status)->toBe('closed')
        ->and($order->variance_journal_entry_id)->toBeNull();
});

it('acumula la cantidad producida a lo largo de varios recibos', function () {
    $f = productionFixture();

    issueToProduction($f, 30);
    receiveProduction($f, 6);

    issueToProduction($f, 20);
    receiveProduction($f, 4);

    expect((float) $f['order']->fresh()->produced_quantity)->toBe(10.0)
        ->and((float) $f['product']->fresh()->onHand())->toBe(10.0)
        ->and((float) $f['order']->fresh()->wipBalance())->toBe(0.0);
});

it('rechaza recibir producto de una orden sin costo acumulado', function () {
    $f = productionFixture();

    receiveProduction($f, 10);
})->throws(InvalidProductionException::class);

it('rechaza operar sobre una orden ya cerrada', function () {
    $f = productionFixture();

    app(PostProductionService::class)->close($f['company'], $f['documentType'], $f['order'], now());

    issueToProduction($f, 10);
})->throws(InvalidProductionException::class);

it('rechaza emitir más materia prima de la que hay en existencia', function () {
    $f = productionFixture();

    issueToProduction($f, 101);
})->throws(InsufficientStockException::class);

it('exige la orden de fabricación en una operación de producción', function () {
    $f = productionFixture();

    app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'production_issue', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1)],
    );
})->throws(InvalidStockMovementException::class);

it('el movimiento de producción queda enlazado a su orden y deja kardex', function () {
    $f = productionFixture();

    $document = issueToProduction($f, 50);

    expect($document->production_order_id)->toBe($f['order']->id)
        ->and(StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole()->direction)->toBe('out');
});

it('el asiento de producción cuadra en moneda extranjera', function () {
    $f = productionFixture();

    issueToProduction($f, 50);
    $document = receiveProduction($f, 10);

    $details = JournalDetail::where('journal_entry_id', $document->journal_entry_id)->get();

    expect($details->sum(fn ($d) => (float) $d->debit_foreign))
        ->toBe($details->sum(fn ($d) => (float) $d->credit_foreign));
});

it('ATOMICIDAD: sin cuenta de WIP no queda ni movimiento ni kardex', function () {
    $f = productionFixture();
    GlDetermination::where('company_id', $f['company']->id)->where('category', 'wip')->delete();

    $existenciaAntes = (float) $f['item']->fresh()->onHand();
    $asientosAntes = JournalEntry::count();

    try {
        issueToProduction($f, 50);
        $this->fail('Debió lanzar MissingGlDeterminationException.');
    } catch (MissingGlDeterminationException) {
        // esperado
    }

    expect(JournalEntry::count())->toBe($asientosAntes)
        ->and((float) $f['item']->fresh()->onHand())->toBe($existenciaAntes)
        ->and((float) $f['order']->wipBalance())->toBe(0.0);
});
