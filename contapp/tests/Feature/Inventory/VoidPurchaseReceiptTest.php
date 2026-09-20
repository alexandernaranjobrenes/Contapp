<?php

use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\UnvoidableInventoryDocumentException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PostSupplierInvoiceService;

function voidReceipt(array $f, InventoryDocument $receipt): InventoryDocument
{
    return app(PostStockMovementService::class)->void(
        company: $f['company'],
        document: $receipt,
        postingDate: now(),
    );
}

it('la anulación saca del inventario exactamente lo que la entrada había metido', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    expect((float) $f['item']->fresh()->onHand())->toBe(100.0);

    voidReceipt($f, $receipt);

    expect((float) $f['item']->fresh()->onHand())->toBe(0.0);
});

it('LA PRUEBA CENTRAL: la cuenta puente vuelve a cero al anular la entrada', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    voidReceipt($f, $receipt);

    $saldo = JournalDetail::where('account_id', $f['accounts']['gr_ir_clearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // La entrada acreditó 100.000 y su anulación debitó los mismos 100.000:
    // el pasivo provisional nunca llegó a convertirse en deuda real.
    expect($saldo)->toBe(0.0);
});

it('el asiento de anulación es el espejo exacto del original y lo deja anulado', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    $reversal = voidReceipt($f, $receipt);

    $originalEntry = JournalEntry::find($receipt->journal_entry_id);
    $reversalEntry = JournalEntry::find($reversal->journal_entry_id);

    expect($originalEntry->status)->toBe('voided')
        ->and($reversalEntry->reversal_of_id)->toBe($originalEntry->id)
        ->and($reversalEntry->details->sum(fn ($d) => (float) $d->debit_local))
        ->toBe($originalEntry->details->sum(fn ($d) => (float) $d->credit_local));
});

it('el documento original queda anulado y el espejo apunta a él', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    $reversal = voidReceipt($f, $receipt);

    expect($receipt->fresh()->status)->toBe('voided')
        ->and($reversal->operation)->toBe('purchase_receipt_void')
        ->and($reversal->reversal_of_id)->toBe($receipt->id)
        ->and($reversal->business_partner_id)->toBe($f['supplier']->id);
});

it('la fila de kardex de la anulación apunta a la que revierte', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    $original = StockJournal::where('journal_entry_id', $receipt->journal_entry_id)->sole();
    $reversal = voidReceipt($f, $receipt);
    $espejo = StockJournal::where('journal_entry_id', $reversal->journal_entry_id)->sole();

    expect($espejo->direction)->toBe('out')
        ->and($espejo->reversal_of_id)->toBe($original->id)
        ->and((float) $espejo->total_cost_local)->toBe((float) $original->total_cost_local)
        ->and((float) $espejo->total_cost_foreign)->toBe((float) $original->total_cost_foreign);
});

it('LA PRUEBA QUE JUSTIFICA EL DISEÑO: revierte al costo original, no al promedio de hoy', function () {
    $f = purchaseFixture();

    $primera = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);
    postPurchaseReceipt($f, quantity: 100, unitCost: 3000);

    // Promedio mezclado: (100×1000 + 100×3000) / 200 = 2.000
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(2000.0);

    voidReceipt($f, $primera);

    // Al sacar las 100 unidades a sus ₡1.000 originales, lo que queda son
    // exactamente las 100 de la segunda entrada a ₡3.000. Revertir al
    // promedio (₡2.000) habría dejado el artículo valuado en ₡1.000.
    expect((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(3000.0);
});

it('el inventario queda valuado igual que la cuenta contable tras anular', function () {
    $f = purchaseFixture();

    $primera = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);
    postPurchaseReceipt($f, quantity: 100, unitCost: 3000);

    voidReceipt($f, $primera);

    $saldoContable = JournalDetail::where('account_id', $f['accounts']['inventory']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    $item = $f['item']->fresh();

    expect((float) $item->onHand() * (float) $item->avg_cost_local)->toBe($saldoContable);
});

it('rechaza anular una entrada que ya tiene factura del proveedor', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
    );

    voidReceipt($f, $receipt->fresh());
})->throws(UnvoidableInventoryDocumentException::class);

it('rechaza anular dos veces la misma entrada', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    voidReceipt($f, $receipt);
    voidReceipt($f, $receipt->fresh());
})->throws(UnvoidableInventoryDocumentException::class);

it('rechaza anular un movimiento que no es una entrada por compra', function () {
    $f = purchaseFixture();

    $entrada = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'goods_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000)],
    );

    voidReceipt($f, $entrada);
})->throws(UnvoidableInventoryDocumentException::class);

it('rechaza anular si la mercancía ya salió del almacén', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'goods_issue', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 40)],
    );

    // Quedan 60 de las 100 que trajo la entrada: no alcanza para devolverlas.
    voidReceipt($f, $receipt->fresh());
})->throws(InsufficientStockException::class);

it('rechaza anular cuando la mercancía ya se consumió mezclada en el promedio', function () {
    $f = purchaseFixture();

    // Una entrada cara y una barata; luego se consume casi todo al promedio.
    $cara = postPurchaseReceipt($f, quantity: 10, unitCost: 10000);
    postPurchaseReceipt($f, quantity: 990, unitCost: 100);

    app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'goods_issue', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 990)],
    );

    // Quedan 10 unidades, pero valen el promedio mezclado (₡199), no los
    // ₡10.000 de la entrada cara: sacarlas a su costo original dejaría el
    // inventario valiendo menos que cero.
    voidReceipt($f, $cara->fresh());
})->throws(UnvoidableInventoryDocumentException::class);

it('ATOMICIDAD: si el asiento de anulación falla no se toca el inventario', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    // Cerrar el período deja sin lugar al asiento espejo: la anulación entera
    // debe revertirse, no solo su mitad contable.
    FiscalPeriod::query()->update(['status' => 'closed']);

    try {
        voidReceipt($f, $receipt);
        $this->fail('Debió rechazar la anulación por período cerrado.');
    } catch (RuntimeException) {
        // esperado
    }

    expect((float) $f['item']->fresh()->onHand())->toBe(100.0)
        ->and($receipt->fresh()->status)->toBe('posted')
        ->and(InventoryDocument::where('operation', 'purchase_receipt_void')->count())->toBe(0);
});

it('la entrada anulada desaparece de la bandeja de pendientes de facturar', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    expect(InventoryDocument::pendingInvoice()->count())->toBe(1);

    voidReceipt($f, $receipt);

    expect(InventoryDocument::pendingInvoice()->count())->toBe(0);
});
