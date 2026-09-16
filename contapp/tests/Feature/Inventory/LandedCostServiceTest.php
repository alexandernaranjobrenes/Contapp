<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidLandedCostException;
use App\Domains\Inventory\Exceptions\MissingGlDeterminationException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\LandedCostDocument;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\PostLandedCostService;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\StockRevaluationSplitter;

function landedCostFixture(): array
{
    $f = purchaseFixture();

    $f['accounts']['price_difference'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'price_difference', 'account_id' => $f['accounts']['price_difference']->id,
    ]);

    return $f;
}

function applyLandedCost(array $f, $amount, ?DateTimeInterface $dueDate = null): LandedCostDocument
{
    return app(PostLandedCostService::class)->post(
        company: $f['company'],
        documentType: $f['invoiceType'],
        receipt: $f['receipt'],
        businessPartnerId: $f['supplier']->id,
        amount: $amount,
        documentDate: now(),
        postingDate: now(),
        dueDate: $dueDate,
        description: 'Flete marítimo',
    );
}

it('capitaliza el costo completo cuando toda la mercancía sigue en existencia', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    $document = applyLandedCost($f, 50000);

    expect((float) $document->capitalized_amount)->toBe(50000.0)
        ->and((float) $document->expensed_amount)->toBe(0.0)
        // ₡500.000 + ₡50.000 repartidos entre 100 u = ₡5.500/u
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5500.0)
        // La cantidad NO cambia: una revaluación no mueve unidades.
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('LA PRUEBA CENTRAL: manda a resultados la parte cuya mercancía ya se vendió', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    // Se venden 60 de las 100 antes de que llegue la factura del flete.
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 60),
    ]);

    $document = applyLandedCost($f, 50000);

    // Quedan 40 de 100 → 40% capitaliza, 60% ya no tiene activo que respaldar.
    expect((float) $document->capitalized_amount)->toBe(20000.0)
        ->and((float) $document->expensed_amount)->toBe(30000.0);

    $entry = JournalEntry::find($document->journal_entry_id);
    $diferencia = $entry->details->firstWhere('account_id', $f['accounts']['price_difference']->id);

    expect((float) $diferencia->debit_local)->toBe(30000.0)
        // Las 40 unidades que quedan absorben ₡20.000: ₡5.000 + ₡500 = ₡5.500
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5500.0);
});

it('manda TODO a resultados cuando ya no queda existencia', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100),
    ]);

    $document = applyLandedCost($f, 50000);

    expect((float) $document->capitalized_amount)->toBe(0.0)
        ->and((float) $document->expensed_amount)->toBe(50000.0)
        // Sin existencia, el promedio no se toca: no hay unidades que revaluar.
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5000.0)
        // Y no deja fila de kardex: no hubo cambio de valor del inventario.
        ->and(StockJournal::where('direction', 'revaluation')->count())->toBe(0);
});

it('deja fila de revaluación en el kardex con cantidad cero y el promedio resultante', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    applyLandedCost($f, 50000);

    $revaluacion = StockJournal::where('direction', 'revaluation')->sole();

    expect((float) $revaluacion->quantity)->toBe(0.0)
        ->and((float) $revaluacion->total_cost_local)->toBe(50000.0)
        ->and((float) $revaluacion->balance_quantity)->toBe(100.0)
        ->and((float) $revaluacion->avg_cost_local_after)->toBe(5500.0)
        ->and($revaluacion->inventory_document_line_id)->toBeNull()
        ->and($revaluacion->landed_cost_allocation_id)->not->toBeNull();
});

it('acredita al transportista y le abre partida pendiente en CxP', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 10, unitCost: 1000);

    $document = applyLandedCost($f, 3000, dueDate: now()->addDays(15));

    $entry = JournalEntry::find($document->journal_entry_id);
    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);
    $partida = BpOpenItem::where('origin_journal_detail_id', $cxp->id)->sole();

    expect((float) $cxp->credit_local)->toBe(3000.0)
        ->and((float) $partida->balance)->toBe(3000.0)
        ->and($partida->due_date->format('Y-m-d'))->toBe(now()->addDays(15)->format('Y-m-d'));
});

it('reparte el costo entre varios artículos en proporción a su valor', function () {
    $f = landedCostFixture();

    $segundo = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2',
    ]);

    $f['receipt'] = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [
            // ₡30.000 de valor
            new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 3000),
            // ₡10.000 de valor → el reparto debe ser 75% / 25%
            new StockLineInput($segundo->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
        ],
        businessPartnerId: $f['supplier']->id,
    );

    $document = applyLandedCost($f, 4000);

    $porArticulo = $document->allocations->keyBy('item_id');

    expect((float) $porArticulo[$f['item']->id]->allocated_amount)->toBe(3000.0)
        ->and((float) $porArticulo[$segundo->id]->allocated_amount)->toBe(1000.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(3300.0)
        ->and((float) $segundo->fresh()->avg_cost_local)->toBe(1100.0);
});

it('el reparto suma exactamente el total aunque el prorrateo no dé exacto', function () {
    $f = landedCostFixture();

    $segundo = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2',
    ]);
    $tercero = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-3',
    ]);

    $f['receipt'] = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [
            new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
            new StockLineInput($segundo->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
            new StockLineInput($tercero->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 1000),
        ],
        businessPartnerId: $f['supplier']->id,
    );

    // 100 / 3 = 33,333... — el residuo tiene que caer en la última línea.
    $document = applyLandedCost($f, 100);

    $suma = $document->allocations->sum(fn ($a) => (float) $a->allocated_amount);

    expect($suma)->toBe(100.0)
        ->and((float) $document->capitalized_amount + (float) $document->expensed_amount)->toBe(100.0);
});

it('ATOMICIDAD: sin cuenta de diferencia de precio no queda nada contabilizado', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 60),
    ]);

    GlDetermination::where('company_id', $f['company']->id)->where('category', 'price_difference')->delete();

    $asientosAntes = JournalEntry::count();
    $promedioAntes = (float) $f['item']->fresh()->avg_cost_local;

    try {
        applyLandedCost($f, 50000);
        $this->fail('Debió lanzar MissingGlDeterminationException.');
    } catch (MissingGlDeterminationException) {
        // esperado
    }

    expect(JournalEntry::count())->toBe($asientosAntes)
        ->and(LandedCostDocument::count())->toBe(0)
        ->and(StockJournal::where('direction', 'revaluation')->count())->toBe(0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe($promedioAntes);
});

it('rechaza aplicar un costo sobre una salida o un ajuste', function () {
    $f = landedCostFixture();
    postPurchaseReceipt($f, quantity: 10, unitCost: 1000);

    $f['receipt'] = postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1),
    ]);

    applyLandedCost($f, 500);
})->throws(InvalidLandedCostException::class);

it('rechaza un monto en cero', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 10, unitCost: 1000);

    applyLandedCost($f, 0);
})->throws(InvalidLandedCostException::class);

it('varios costos sucesivos se acumulan sobre el mismo promedio', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    applyLandedCost($f, 50000);  // +500/u
    applyLandedCost($f, 20000);  // +200/u

    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(5700.0)
        ->and(StockJournal::where('direction', 'revaluation')->count())->toBe(2);
});

it('el asiento de la revaluación cuadra en moneda extranjera', function () {
    $f = landedCostFixture();
    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 60),
    ]);

    $document = applyLandedCost($f, 50000);

    $details = JournalDetail::where('journal_entry_id', $document->journal_entry_id)->get();

    $debitos = $details->sum(fn ($d) => (float) $d->debit_foreign);
    $creditos = $details->sum(fn ($d) => (float) $d->credit_foreign);

    expect($debitos)->toBe($creditos)
        ->and($debitos)->toBeGreaterThan(0.0);
});

// --- Reparto puro, sin base de datos ---

it('el repartidor capitaliza en proporción a lo que queda', function () {
    $splitter = new StockRevaluationSplitter;

    expect($splitter->split('1000.00', '100.000000', '100.000000'))
        ->toBe(['capitalized' => '1000.00', 'expensed' => '0.00'])
        ->and($splitter->split('1000.00', '100.000000', '40.000000'))
        ->toBe(['capitalized' => '400.00', 'expensed' => '600.00'])
        ->and($splitter->split('1000.00', '100.000000', '0.000000'))
        ->toBe(['capitalized' => '0.00', 'expensed' => '1000.00'])
        // Más existencia que lo recibido (entró por otra compra): se capitaliza todo.
        ->and($splitter->split('1000.00', '100.000000', '250.000000'))
        ->toBe(['capitalized' => '1000.00', 'expensed' => '0.00']);
});

it('el repartidor nunca pierde ni inventa céntimos', function () {
    $splitter = new StockRevaluationSplitter;

    $allocations = $splitter->allocateByValue('100.00', ['1000.000000', '1000.000000', '1000.000000']);

    expect(array_sum(array_map('floatval', $allocations)))->toBe(100.0);

    $split = $splitter->split('33.33', '7.000000', '3.000000');

    expect(bcadd($split['capitalized'], $split['expensed'], 2))->toBe('33.33');
});
