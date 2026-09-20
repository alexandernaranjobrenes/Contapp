<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Inventory\DataTransferObjects\PurchaseReturnLineInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseReturnException;
use App\Domains\Inventory\Exceptions\MissingGlDeterminationException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PostSupplierCreditNoteService;
use App\Domains\Inventory\Services\PostSupplierInvoiceService;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

/**
 * Compra completa y facturada, lista para devolver: 100 u a ₡1.000 con su
 * factura ya emitida, más la cuenta de diferencia de precio que la devolución
 * necesita cuando lo acreditado no coincide con el costo.
 */
function creditNoteFixture(): array
{
    $f = purchaseFixture();

    $f['accounts']['price_difference'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'price_difference', 'account_id' => $f['accounts']['price_difference']->id,
    ]);

    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 1000);

    $f['invoice'] = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $f['receipt'], now(), now(), dueDate: now()->addDays(30),
    );

    return $f;
}

function creditNote(array $f, $quantity = 20, array $overrides = []): InventoryDocument
{
    $line = $f['receipt']->lines->first();

    return app(PostSupplierCreditNoteService::class)->post(
        company: $f['company'],
        documentType: $f['invoiceType'],
        receipt: $overrides['receipt'] ?? $f['receipt']->fresh(),
        documentDate: now(),
        postingDate: now(),
        lines: [new PurchaseReturnLineInput(
            receiptLineId: $overrides['lineId'] ?? $line->id,
            quantity: $quantity,
            creditedUnitPrice: $overrides['price'] ?? null,
        )],
        taxAccountId: $overrides['taxAccountId'] ?? null,
        taxAmount: $overrides['taxAmount'] ?? 0,
    );
}

it('la devolución saca la mercancía del inventario y baja la existencia', function () {
    $f = creditNoteFixture();

    creditNote($f, 20);

    expect((float) $f['item']->fresh()->onHand())->toBe(80.0)
        // Una devolución no cambia cuánto vale lo que queda.
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0);
});

it('LA PRUEBA CENTRAL: la cuenta puente vuelve a cerrar en cero tras la devolución', function () {
    $f = creditNoteFixture();

    creditNote($f, 20);

    $saldo = JournalDetail::where('account_id', $f['accounts']['gr_ir_clearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // Recepción acredita 100.000, factura debita 100.000, devolución debita
    // 20.000 y su nota acredita 20.000: la bisagra cierra en las dos vueltas.
    expect($saldo)->toBe(0.0);
});

it('la nota debita la cuenta del proveedor y reduce la partida pendiente en CxP', function () {
    $f = creditNoteFixture();

    $partida = BpOpenItem::whereHas('businessPartner', fn ($q) => $q->where('id', $f['supplier']->id))->sole();
    expect((float) $partida->balance)->toBe(100000.0);

    creditNote($f, 20);

    $partida->refresh();

    // 100.000 de deuda menos 20.000 devueltos.
    expect((float) $partida->balance)->toBe(80000.0)
        ->and($partida->status)->toBe('partial');
});

it('el asiento de la nota queda enlazado al documento de devolución', function () {
    $f = creditNoteFixture();

    $document = creditNote($f, 20);

    expect($document->operation)->toBe('purchase_return')
        ->and($document->journal_entry_id)->not->toBeNull()
        ->and($document->invoice_journal_entry_id)->not->toBeNull()
        ->and($document->source_document_id)->toBe($f['receipt']->id)
        ->and($document->business_partner_id)->toBe($f['supplier']->id);
});

it('la salida queda en el kardex como movimiento de salida', function () {
    $f = creditNoteFixture();

    $document = creditNote($f, 20);
    $kardex = StockJournal::where('journal_entry_id', $document->journal_entry_id)->sole();

    expect($kardex->direction)->toBe('out')
        ->and((float) $kardex->quantity)->toBe(20.0)
        ->and((float) $kardex->total_cost_local)->toBe(20000.0);
});

it('acreditar MENOS que el costo de la mercancía deja la diferencia en resultados', function () {
    $f = creditNoteFixture();

    // Se devuelven 20 u que costaron ₡1.000, pero el proveedor solo acredita
    // ₡900 por unidad (cargo por reposición): ₡2.000 de pérdida real.
    $document = creditNote($f, 20, ['price' => 900]);

    $entry = JournalEntry::find($document->invoice_journal_entry_id);
    $diferencia = $entry->details->firstWhere('account_id', $f['accounts']['price_difference']->id);
    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);

    expect((float) $diferencia->debit_local)->toBe(2000.0)
        ->and((float) $cxp->debit_local)->toBe(18000.0);
});

it('acreditar MÁS que el costo deja la diferencia como ganancia', function () {
    $f = creditNoteFixture();

    $document = creditNote($f, 20, ['price' => 1100]);

    $entry = JournalEntry::find($document->invoice_journal_entry_id);
    $diferencia = $entry->details->firstWhere('account_id', $f['accounts']['price_difference']->id);

    expect((float) $diferencia->credit_local)->toBe(2000.0);
});

it('una devolución al mismo precio no genera diferencia', function () {
    $f = creditNoteFixture();

    $document = creditNote($f, 20);
    $entry = JournalEntry::find($document->invoice_journal_entry_id);

    expect($entry->details->firstWhere('account_id', $f['accounts']['price_difference']->id))->toBeNull();
});

it('revierte el IVA de la parte devuelta', function () {
    $f = creditNoteFixture();

    $taxType = TaxType::factory()->create(['company_id' => $f['company']->id]);
    $taxRate = TaxRate::factory()->create([
        'company_id' => $f['company']->id, 'tax_type_id' => $taxType->id, 'percentage' => '13.00',
    ]);
    $ivaAccount = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'tax_rate_id' => $taxRate->id,
    ]);

    // 20 u × ₡1.000 = ₡20.000; 13% = ₡2.600
    $document = creditNote($f, 20, ['taxAccountId' => $ivaAccount->id, 'taxAmount' => 2600]);

    $entry = JournalEntry::find($document->invoice_journal_entry_id);

    expect((float) $entry->details->firstWhere('account_id', $ivaAccount->id)->credit_local)->toBe(2600.0)
        ->and((float) $entry->details->firstWhere('account_id', $f['accounts']['payable']->id)->debit_local)->toBe(22600.0);
});

it('varias devoluciones sucesivas no pueden superar lo recibido', function () {
    $f = creditNoteFixture();

    creditNote($f, 60);
    creditNote($f, 30);

    // Quedan 10 sin devolver; pedir 20 debe rechazarse.
    creditNote($f, 20);
})->throws(InvalidPurchaseReturnException::class);

it('permite devolver exactamente lo que queda', function () {
    $f = creditNoteFixture();

    creditNote($f, 60);
    creditNote($f, 40);

    expect((float) $f['item']->fresh()->onHand())->toBe(0.0);
});

it('rechaza devolver contra una recepción que todavía no fue facturada', function () {
    $f = creditNoteFixture();

    $sinFacturar = postPurchaseReceipt($f, quantity: 10, unitCost: 1000);

    creditNote($f, 5, ['receipt' => $sinFacturar, 'lineId' => $sinFacturar->lines->first()->id]);
})->throws(InvalidPurchaseReturnException::class);

it('rechaza devolver contra una entrada que no es por compra', function () {
    $f = creditNoteFixture();

    $entrada = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'goods_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 5, unitCostLocal: 1000)],
    );

    creditNote($f, 1, ['receipt' => $entrada, 'lineId' => $entrada->lines->first()->id]);
})->throws(InvalidPurchaseReturnException::class);

it('rechaza una línea que no pertenece a la recepción', function () {
    $f = creditNoteFixture();
    $otra = postPurchaseReceipt($f, quantity: 5, unitCost: 1000);

    creditNote($f, 1, ['lineId' => $otra->lines->first()->id]);
})->throws(InvalidPurchaseReturnException::class);

it('el asiento de la nota cuadra en las tres monedas', function () {
    $f = creditNoteFixture();

    $document = creditNote($f, 20, ['price' => 900]);
    $details = JournalDetail::where('journal_entry_id', $document->invoice_journal_entry_id)->get();

    foreach (['local', 'foreign', 'system'] as $bucket) {
        expect($details->sum(fn ($d) => (float) $d->{"debit_{$bucket}"}))
            ->toBe($details->sum(fn ($d) => (float) $d->{"credit_{$bucket}"}));
    }
});

it('ATOMICIDAD: si la nota falla no queda salida de mercancía ni asiento', function () {
    $f = creditNoteFixture();
    GlDetermination::where('company_id', $f['company']->id)->where('category', 'price_difference')->delete();

    $existenciaAntes = (float) $f['item']->fresh()->onHand();
    $asientosAntes = JournalEntry::count();

    try {
        // Precio distinto al costo fuerza la línea de diferencia, que ya no
        // tiene cuenta configurada.
        creditNote($f, 20, ['price' => 900]);
        $this->fail('Debió lanzar MissingGlDeterminationException.');
    } catch (MissingGlDeterminationException) {
        // esperado
    }

    expect(JournalEntry::count())->toBe($asientosAntes)
        ->and((float) $f['item']->fresh()->onHand())->toBe($existenciaAntes)
        ->and(InventoryDocument::where('operation', 'purchase_return')->count())->toBe(0);
});
