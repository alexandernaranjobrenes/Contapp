<?php

use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidStockCountException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\StockCount;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\StockCountService;

/**
 * Existencia inicial: 100 u a ₡1.000 en el almacén principal.
 */
function countFixture(): array
{
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 1000),
    ]);

    return $f;
}

function openCount(array $f, array $overrides = []): StockCount
{
    return app(StockCountService::class)->open(
        company: $f['company'],
        documentType: $f['documentType'],
        cutoffDate: $overrides['cutoff'] ?? now(),
        warehouseId: $overrides['warehouseId'] ?? $f['warehouse']->id,
        itemGroupId: $overrides['itemGroupId'] ?? null,
        blind: $overrides['blind'] ?? false,
    );
}

function countAndClose(array $f, StockCount $count, $quantity): StockCount
{
    $service = app(StockCountService::class);
    $service->capture($f['company'], $count, [$count->lines->first()->id => $quantity]);

    return $service->post($f['company'], $count->fresh(), now());
}

// --- Abrir: la hoja se arma del kardex al corte ---

it('abre la toma con la existencia teórica congelada del kardex', function () {
    $f = countFixture();

    $count = openCount($f);

    expect($count->status)->toBe('open')
        ->and($count->lines)->toHaveCount(1)
        ->and((float) $count->lines->first()->theoretical_quantity)->toBe(100.0)
        // El costo se congela también, para poder valorar la diferencia.
        ->and((float) $count->lines->first()->unit_cost_local)->toBe(1000.0)
        ->and($count->lines->first()->counted_quantity)->toBeNull();
});

it('LA CLAVE: la teórica es la del CORTE, no la de hoy', function () {
    $f = countFixture();

    // Ayer había 100; hoy entran 40 más.
    StockJournal::query()->update(['posting_date' => now()->subDays(3)->format('Y-m-d')]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 40, unitCostLocal: 1000),
    ]);

    $count = openCount($f, ['cutoff' => now()->subDay()]);

    // La hoja con corte de ayer dice 100, aunque hoy haya 140.
    expect((float) $count->lines->first()->theoretical_quantity)->toBe(100.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(140.0);
});

it('filtra por familia de artículos', function () {
    $f = countFixture();

    $familia = ItemGroup::factory()->create(['company_id' => $f['company']->id, 'code' => 'OTRA']);
    $otro = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-9',
        'is_inventory_item' => true, 'item_group_id' => $familia->id,
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($otro->id, $f['warehouse']->id, quantity: 5, unitCostLocal: 500),
    ]);

    $count = openCount($f, ['itemGroupId' => $familia->id]);

    expect($count->lines)->toHaveCount(1)
        ->and($count->lines->first()->item_id)->toBe($otro->id);
});

it('rechaza abrir dos tomas del mismo almacén a la vez', function () {
    $f = countFixture();

    openCount($f);
    openCount($f);
})->throws(InvalidStockCountException::class);

it('rechaza abrir sobre un almacén sin movimientos a esa fecha', function () {
    $f = countFixture();

    openCount($f, ['cutoff' => now()->subYear()]);
})->throws(InvalidStockCountException::class);

// --- Capturar ---

it('captura lo contado por tandas y deja el resto pendiente', function () {
    $f = countFixture();
    $count = openCount($f);

    app(StockCountService::class)->capture($f['company'], $count, [$count->lines->first()->id => 97]);

    expect((float) $count->fresh()->lines->first()->counted_quantity)->toBe(97.0);
});

it('rechaza una cantidad contada negativa', function () {
    $f = countFixture();
    $count = openCount($f);

    app(StockCountService::class)->capture($f['company'], $count, [$count->lines->first()->id => -1]);
})->throws(InvalidStockCountException::class);

it('rechaza capturar en una línea de otra toma', function () {
    $f = countFixture();
    $count = openCount($f);

    app(StockCountService::class)->capture($f['company'], $count, [999999 => 10]);
})->throws(InvalidStockCountException::class);

// --- Cerrar: el ajuste ---

it('LA PRUEBA CENTRAL: un faltante baja la existencia y la lleva a resultados', function () {
    $f = countFixture();
    $count = openCount($f);

    // Se contaron 97 de las 100 que decía el sistema: faltan 3.
    countAndClose($f, $count, 97);

    $ajuste = InventoryDocument::where('operation', 'count_adjustment')->sole();
    $kardex = StockJournal::where('journal_entry_id', $ajuste->journal_entry_id)->sole();

    expect((float) $f['item']->fresh()->onHand())->toBe(97.0)
        ->and($kardex->direction)->toBe('out')
        ->and((float) $kardex->quantity)->toBe(3.0)
        // 3 u × ₡1.000 de costo promedio
        ->and((float) $kardex->total_cost_local)->toBe(3000.0);
});

it('un sobrante sube la existencia', function () {
    $f = countFixture();
    $count = openCount($f);

    countAndClose($f, $count, 105);

    $ajuste = InventoryDocument::where('operation', 'count_adjustment')->sole();
    $kardex = StockJournal::where('journal_entry_id', $ajuste->journal_entry_id)->sole();

    expect((float) $f['item']->fresh()->onHand())->toBe(105.0)
        ->and($kardex->direction)->toBe('in')
        ->and((float) $kardex->quantity)->toBe(5.0);
});

it('un conteo sin diferencias cierra la toma SIN generar ajuste', function () {
    $f = countFixture();
    $count = openCount($f);

    $cerrada = countAndClose($f, $count, 100);

    // Contar y que todo cuadre también es un resultado, y queda registrado.
    expect($cerrada->status)->toBe('posted')
        ->and($cerrada->inventory_document_id)->toBeNull()
        ->and(InventoryDocument::where('operation', 'count_adjustment')->count())->toBe(0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('la toma cerrada queda enlazada a su ajuste', function () {
    $f = countFixture();
    $count = openCount($f);

    $cerrada = countAndClose($f, $count, 97);
    $ajuste = InventoryDocument::where('operation', 'count_adjustment')->sole();

    expect($cerrada->inventory_document_id)->toBe($ajuste->id)
        ->and($cerrada->posted_at)->not->toBeNull();
});

it('rechaza cerrar con líneas sin contar', function () {
    $f = countFixture();
    $count = openCount($f);

    app(StockCountService::class)->post($f['company'], $count, now());
})->throws(InvalidStockCountException::class);

it('contar en cero es válido y vacía la existencia', function () {
    $f = countFixture();
    $count = openCount($f);

    countAndClose($f, $count, 0);

    expect((float) $f['item']->fresh()->onHand())->toBe(0.0);
});

it('LA GUARDA: rechaza cerrar si la existencia se movió después del corte', function () {
    $f = countFixture();
    $count = openCount($f);

    // Alguien despachó mercancía mientras se contaba.
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    // Aplicar lo contado ahora borraría esa salida.
    countAndClose($f, $count, 97);
})->throws(InvalidStockCountException::class);

it('rechaza cerrar dos veces', function () {
    $f = countFixture();
    $count = openCount($f);

    countAndClose($f, $count, 100);
    app(StockCountService::class)->post($f['company'], $count->fresh(), now());
})->throws(InvalidStockCountException::class);

// --- Cancelar ---

it('cancelar deja la toma sin efecto y libera el almacén', function () {
    $f = countFixture();
    $count = openCount($f);

    app(StockCountService::class)->cancel($f['company'], $count, 'Se suspendió el conteo');

    expect($count->fresh()->status)->toBe('cancelled')
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);

    // Y con el almacén libre se puede abrir otra.
    expect(openCount($f)->status)->toBe('open');
});

it('ATOMICIDAD: si el ajuste falla la toma sigue abierta', function () {
    $f = countFixture();
    $count = openCount($f);

    FiscalPeriod::query()->update(['status' => 'closed']);

    try {
        countAndClose($f, $count, 97);
        $this->fail('Debió rechazar el ajuste por período cerrado.');
    } catch (RuntimeException) {
        // esperado
    }

    expect($count->fresh()->status)->toBe('open')
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and(InventoryDocument::where('operation', 'count_adjustment')->count())->toBe(0);
});

it('los números de toma son consecutivos por compañía', function () {
    $f = countFixture();

    $primera = openCount($f);
    app(StockCountService::class)->cancel($f['company'], $primera);
    $segunda = openCount($f);

    expect($primera->number)->toBe('00000001')
        ->and($segunda->number)->toBe('00000002');
});
