<?php

use App\Domains\Accounting\Exceptions\InvalidTaxAmountException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Exceptions\InvalidSupplierInvoiceException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PostSupplierInvoiceService;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

it('una entrada por compra debita inventario y acredita la cuenta puente GR/IR', function () {
    $f = purchaseFixture();

    $receipt = postPurchaseReceipt($f);
    $entry = JournalEntry::find($receipt->journal_entry_id);

    $inventario = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);
    $puente = $entry->details->firstWhere('account_id', $f['accounts']['gr_ir_clearing']->id);

    expect((float) $inventario->debit_local)->toBe(500000.0)
        ->and((float) $puente->credit_local)->toBe(500000.0)
        // No toca el ajuste de aumento: esa cuenta es para entradas sin proveedor.
        ->and($entry->details->firstWhere('account_id', $f['accounts']['stock_increase']->id))->toBeNull()
        ->and($receipt->business_partner_id)->toBe($f['supplier']->id)
        ->and($receipt->invoice_journal_entry_id)->toBeNull();
});

it('exige proveedor en una entrada por compra', function () {
    $f = purchaseFixture();

    app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100)],
    );
})->throws(InvalidStockMovementException::class);

it('rechaza un socio de negocio que no está registrado como proveedor', function () {
    $f = purchaseFixture();
    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'C-001', 'type' => 'client',
        'gl_account_id' => $f['accounts']['payable']->id,
    ]);

    app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100)],
        businessPartnerId: $cliente->id,
    );
})->throws(InvalidStockMovementException::class);

it('LA PRUEBA CENTRAL: la factura deja la cuenta puente GR/IR en cero, en ambas monedas', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
    );

    $rows = JournalDetail::where('account_id', $f['accounts']['gr_ir_clearing']->id)->get();

    $saldoLocal = $rows->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);
    $saldoForeign = $rows->sum(fn ($r) => (float) $r->debit_foreign - (float) $r->credit_foreign);

    expect($rows)->toHaveCount(2)
        ->and($saldoLocal)->toBe(0.0)
        ->and($saldoForeign)->toBe(0.0);
});

it('la factura acredita la cuenta de control del proveedor y abre partida pendiente en CxP', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    $entry = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
        dueDate: now()->addDays(30),
    );

    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);
    $partida = BpOpenItem::where('origin_journal_detail_id', $cxp->id)->sole();

    expect((float) $cxp->credit_local)->toBe(500000.0)
        ->and($cxp->business_partner_id)->toBe($f['supplier']->id)
        ->and((float) $partida->balance)->toBe(500000.0)
        ->and($partida->status)->toBe('open')
        ->and($partida->due_date->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
});

it('la factura con IVA debita la cuenta de impuesto y acredita el total al proveedor', function () {
    $f = purchaseFixture();

    $taxType = TaxType::factory()->create(['company_id' => $f['company']->id]);
    $taxRate = TaxRate::factory()->create([
        'company_id' => $f['company']->id, 'tax_type_id' => $taxType->id, 'percentage' => '13.00',
    ]);
    $ivaAccount = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id,
        'account_type' => 'asset',
        'tax_classification' => 'iva_soportado',
        'tax_rate_id' => $taxRate->id,
    ]);

    $receipt = postPurchaseReceipt($f);

    $entry = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
        taxAccountId: $ivaAccount->id,
        taxAmount: 65000, // 13% de 500.000
    );

    $iva = $entry->details->firstWhere('account_id', $ivaAccount->id);
    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);

    expect((float) $iva->debit_local)->toBe(65000.0)
        ->and((float) $cxp->credit_local)->toBe(565000.0);
});

it('rechaza un IVA que no corresponde a la tarifa configurada', function () {
    $f = purchaseFixture();

    $taxType = TaxType::factory()->create(['company_id' => $f['company']->id]);
    $taxRate = TaxRate::factory()->create([
        'company_id' => $f['company']->id, 'tax_type_id' => $taxType->id, 'percentage' => '13.00',
    ]);
    $ivaAccount = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'tax_rate_id' => $taxRate->id,
    ]);

    $receipt = postPurchaseReceipt($f);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
        taxAccountId: $ivaAccount->id,
        taxAmount: 99999,
    );
})->throws(InvalidTaxAmountException::class);

it('rechaza una cuenta de IVA sin indicador de impuesto vinculado', function () {
    $f = purchaseFixture();
    $cualquiera = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    $receipt = postPurchaseReceipt($f);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(),
        taxAccountId: $cualquiera->id, taxAmount: 100,
    );
})->throws(InvalidSupplierInvoiceException::class);

it('no permite facturar dos veces la misma recepción', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    app(PostSupplierInvoiceService::class)->post($f['company'], $f['invoiceType'], $receipt, now(), now());

    app(PostSupplierInvoiceService::class)->post($f['company'], $f['invoiceType'], $receipt->fresh(), now(), now());
})->throws(InvalidSupplierInvoiceException::class);

it('no permite facturar una entrada que no es por compra', function () {
    $f = purchaseFixture();

    $document = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'goods_receipt', now(), now(),
        [new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100)],
    );

    app(PostSupplierInvoiceService::class)->post($f['company'], $f['invoiceType'], $document, now(), now());
})->throws(InvalidSupplierInvoiceException::class);

it('la recepción queda marcada como facturada y sale del pendiente', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    expect(InventoryDocument::pendingInvoice()->count())->toBe(1);

    $entry = app(PostSupplierInvoiceService::class)->post($f['company'], $f['invoiceType'], $receipt, now(), now());

    expect($receipt->fresh()->invoice_journal_entry_id)->toBe($entry->id)
        ->and(InventoryDocument::pendingInvoice()->count())->toBe(0);
});

it('la factura no mueve stock: el kardex queda intacto', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    $kardexAntes = StockJournal::count();
    $existenciaAntes = (float) $f['item']->fresh()->onHand();

    app(PostSupplierInvoiceService::class)->post($f['company'], $f['invoiceType'], $receipt, now(), now());

    expect(StockJournal::count())->toBe($kardexAntes)
        ->and((float) $f['item']->fresh()->onHand())->toBe($existenciaAntes)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5000.0);
});

it('ATOMICIDAD: si la factura falla, la recepción sigue pendiente y no queda asiento', function () {
    $f = purchaseFixture();
    $receipt = postPurchaseReceipt($f);

    $asientosAntes = JournalEntry::count();
    $cualquiera = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);

    try {
        app(PostSupplierInvoiceService::class)->post(
            $f['company'], $f['invoiceType'], $receipt, now(), now(),
            taxAccountId: $cualquiera->id, taxAmount: 100,
        );
        $this->fail('Debió lanzar InvalidSupplierInvoiceException.');
    } catch (InvalidSupplierInvoiceException) {
        // esperado
    }

    expect(JournalEntry::count())->toBe($asientosAntes)
        ->and($receipt->fresh()->invoice_journal_entry_id)->toBeNull()
        ->and(InventoryDocument::pendingInvoice()->count())->toBe(1);
});

// --- Diferencia de precio (Fase 5): el proveedor factura distinto a lo recibido ---

function priceVarianceFixture(): array
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

it('factura por MÁS de lo recibido: capitaliza la diferencia si el stock sigue entero', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    // Se recibió por ₡500.000 pero la factura llega por ₡520.000.
    $entry = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 520000,
    );

    $inventario = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);
    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);

    expect((float) $inventario->debit_local)->toBe(20000.0)
        ->and((float) $cxp->credit_local)->toBe(520000.0)
        // ₡520.000 / 100 u = ₡5.200
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5200.0);
});

it('factura por MÁS con mercancía ya vendida: parte capitaliza, parte va a resultados', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 60),
    ]);

    $entry = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 520000,
    );

    $inventario = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);
    $diferencia = $entry->details->firstWhere('account_id', $f['accounts']['price_difference']->id);

    // Quedan 40 de 100 → 40% de ₡20.000 capitaliza, 60% a resultados.
    expect((float) $inventario->debit_local)->toBe(8000.0)
        ->and((float) $diferencia->debit_local)->toBe(12000.0)
        // Las 40 restantes absorben ₡8.000: ₡5.000 + ₡200 = ₡5.200
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5200.0);
});

it('factura por MENOS de lo recibido: acredita inventario y baja el promedio', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    $entry = app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 450000,
    );

    $inventario = $entry->details->firstWhere('account_id', $f['accounts']['inventory']->id);
    $cxp = $entry->details->firstWhere('account_id', $f['accounts']['payable']->id);

    expect((float) $inventario->credit_local)->toBe(50000.0)
        ->and((float) $cxp->credit_local)->toBe(450000.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(4500.0);
});

it('la diferencia de precio deja fila de revaluación apuntando a la línea de la recepción', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 520000,
    );

    $revaluacion = StockJournal::where('direction', 'revaluation')->sole();

    expect((float) $revaluacion->quantity)->toBe(0.0)
        ->and((float) $revaluacion->total_cost_local)->toBe(20000.0)
        ->and((float) $revaluacion->avg_cost_local_after)->toBe(5200.0)
        ->and($revaluacion->inventory_document_line_id)->toBe($receipt->lines->first()->id)
        ->and($revaluacion->landed_cost_allocation_id)->toBeNull();
});

it('una baja de valor queda en el kardex con signo negativo', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 450000,
    );

    expect((float) StockJournal::where('direction', 'revaluation')->sole()->total_cost_local)->toBe(-50000.0);
});

it('la cuenta puente sigue cerrando en cero aunque la factura traiga diferencia', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 520000,
    );

    $saldo = JournalDetail::where('account_id', $f['accounts']['gr_ir_clearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($saldo)->toBe(0.0);
});

it('un neto igual al recibido no genera diferencia ni revaluación', function () {
    $f = priceVarianceFixture();
    $receipt = postPurchaseReceipt($f, quantity: 100, unitCost: 5000);

    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $receipt, now(), now(), netAmount: 500000,
    );

    expect(StockJournal::where('direction', 'revaluation')->count())->toBe(0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(5000.0);
});

/**
 * Solo es alcanzable cuando la rebaja supera el valor total del stock, y eso
 * exige que el promedio haya bajado por otra vía: se vende casi toda la compra
 * cara y entra después una compra barata. Raro, pero real — y es exactamente
 * el caso que hay que rechazar antes de escribir un costo imposible.
 */
it('rechaza una diferencia que dejaría el costo promedio en negativo', function () {
    $f = priceVarianceFixture();

    $caro = postPurchaseReceipt($f, quantity: 100, unitCost: 10000);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 90),
    ]);

    postPurchaseReceipt($f, quantity: 100, unitCost: 100);

    // Quedan 110 u valuadas en ₡110.000 → promedio ₡1.000.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0);

    // Rebajar ₡999.999 sobre 110 unidades son ₡9.090 por unidad: imposible.
    app(PostSupplierInvoiceService::class)->post(
        $f['company'], $f['invoiceType'], $caro, now(), now(), netAmount: 1,
    );
})->throws(InvalidSupplierInvoiceException::class);
