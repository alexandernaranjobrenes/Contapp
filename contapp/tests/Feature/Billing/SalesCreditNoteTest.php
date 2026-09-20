<?php

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\StockJournal;

/**
 * Una nota de crédito siempre corrige un comprobante: la referencia fiscal la
 * exige Hacienda y el enlace interno es el que trae el costo y la partida.
 */
function creditNoteOn(SalesDocument $original, array $f, array $overrides = []): SalesDocument
{
    return postSale($f, array_merge([
        'fiscalType' => '03',
        'references' => [[
            'document_type' => '01',
            'number' => $original->clave,
            'issued_at' => $original->document_date->format('Y-m-d'),
            'reason_code' => '01',
            'reason' => 'Devolución de mercancía',
        ]],
        'originalId' => $original->id,
    ], $overrides));
}

it('LA CORRECCIÓN: la nota de crédito devuelve la mercancía al inventario', function () {
    $f = salesFixture();

    postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    // 100 recibidas menos 10 vendidas.
    expect((float) $f['item']->fresh()->onHand())->toBe(90.0);

    $venta = SalesDocument::where('fiscal_document_type', '01')->sole();
    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 4)]]);

    // Vuelven 4: la mercancía entra, no vuelve a salir.
    expect((float) $f['item']->fresh()->onHand())->toBe(94.0);
});

it('el movimiento de la devolución es una ENTRADA al kardex', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 4)]]);
    $documento = InventoryDocument::find($nota->inventory_document_id);
    $kardex = StockJournal::where('journal_entry_id', $documento->journal_entry_id)->sole();

    expect($documento->operation)->toBe('sales_return')
        ->and($kardex->direction)->toBe('in')
        ->and((float) $kardex->quantity)->toBe(4.0);
});

it('reingresa al costo con que salió, no al promedio de hoy', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    // Entra mercancía más cara: el promedio sube a ₡1.900.
    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id, quantity: 90, unitCostLocal: 2900
        ),
    ]);

    $nota = creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 10)]]);
    $documento = InventoryDocument::find($nota->inventory_document_id);
    $kardex = StockJournal::where('journal_entry_id', $documento->journal_entry_id)->sole();

    // Las 10 unidades habían salido a ₡1.000: vuelven a ₡1.000.
    expect((float) $kardex->unit_cost_local)->toBe(1000.0)
        ->and((float) $kardex->total_cost_local)->toBe(10000.0);
});

it('LA PRUEBA CENTRAL: el costo de ventas queda en cero si se devuelve todo', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 10)]]);

    $saldo = JournalDetail::where('account_id', $f['accounts']['cogs']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // La venta cargó ₡10.000 a costo de ventas y la devolución los descargó.
    expect($saldo)->toBe(0.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('el asiento de la nota debita el ingreso y el IVA que la venta acreditó', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 10)]]);
    $entry = JournalEntry::find($nota->journal_entry_id);

    $ingreso = $entry->details->firstWhere('account_id', $f['accounts']['revenue']->id);
    $iva = $entry->details->firstWhere('account_id', $f['accounts']['iva']->id);
    $cliente = $entry->details->firstWhere('account_id', $f['accounts']['receivable']->id);

    // 10 u × ₡2.500 = ₡25.000 de ingreso, 13% = ₡3.250 de IVA.
    expect((float) $ingreso->debit_local)->toBe(25000.0)
        ->and((float) $iva->debit_local)->toBe(3250.0)
        ->and((float) $cliente->credit_local)->toBe(28250.0);
});

it('la nota cancela la partida de CxC que abrió la venta, no abre otra', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $partida = BpOpenItem::sole();
    expect((float) $partida->balance)->toBe(28250.0);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 4)]]);

    // 4 de 10 devueltas: ₡11.300 menos de deuda, sobre la MISMA partida.
    expect(BpOpenItem::count())->toBe(1)
        ->and((float) $partida->fresh()->balance)->toBe(16950.0)
        ->and($partida->fresh()->status)->toBe('partial');
});

it('devolver todo deja la partida en cero y cerrada', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 10)]]);

    $partida = BpOpenItem::sole();

    expect((float) $partida->balance)->toBe(0.0)
        ->and($partida->status)->toBe('closed');
});

it('el asiento de la nota cuadra en las tres monedas', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 3)]]);
    $details = JournalDetail::where('journal_entry_id', $nota->journal_entry_id)->get();

    foreach (['local', 'foreign', 'system'] as $bucket) {
        expect($details->sum(fn ($d) => (float) $d->{"debit_{$bucket}"}))
            ->toBe($details->sum(fn ($d) => (float) $d->{"credit_{$bucket}"}));
    }
});

it('la nota queda enlazada al comprobante que corrige', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 3)]]);

    expect($nota->original_sales_document_id)->toBe($venta->id)
        ->and($nota->isCreditNote())->toBeTrue();
});

it('rechaza una nota con mercancía que no dice qué comprobante corrige', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 3)], 'originalId' => null]);
})->throws(InvalidSalesDocumentException::class);

it('no deja devolver más de lo que se vendió', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 11)]]);
})->throws(InvalidSalesDocumentException::class);

it('varias notas sucesivas no pueden superar lo vendido', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 6)]]);
    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 3)]]);

    // Queda 1 por devolver; pedir 2 debe rechazarse.
    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 2)]]);
})->throws(InvalidSalesDocumentException::class);

it('rechaza devolver un artículo que no estaba en el comprobante original', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $otro = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2', 'is_inventory_item' => true,
    ]);

    creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 1, overrides: ['itemId' => $otro->id])]]);
})->throws(InvalidSalesDocumentException::class);

it('una nota de crédito solo de servicios no toca el inventario', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $nota = creditNoteOn($venta, $f, [
        'lines' => [saleLine($f, quantity: 1, price: 5000, overrides: ['isService' => true, 'itemId' => null, 'warehouseId' => null])],
    ]);

    expect($nota->inventory_document_id)->toBeNull()
        // La venta sigue habiendo sacado sus 10 unidades y nada volvió.
        ->and((float) $f['item']->fresh()->onHand())->toBe(90.0);
});

it('ATOMICIDAD: si la nota falla no queda comprobante ni movimiento', function () {
    $f = salesFixture();
    $venta = postSale($f, ['lines' => [saleLine($f, quantity: 10)]]);

    $comprobantesAntes = SalesDocument::count();
    $movimientosAntes = InventoryDocument::count();

    try {
        creditNoteOn($venta, $f, ['lines' => [saleLine($f, quantity: 99)]]);
        $this->fail('Debió rechazar la devolución por exceder lo vendido.');
    } catch (InvalidSalesDocumentException) {
        // esperado
    }

    expect(SalesDocument::count())->toBe($comprobantesAntes)
        ->and(InventoryDocument::count())->toBe($movimientosAntes);
});
