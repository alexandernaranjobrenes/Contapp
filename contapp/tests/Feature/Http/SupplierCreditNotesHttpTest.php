<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;

/**
 * Compra recibida y facturada por HTTP, que es de donde arranca el "Copiar a"
 * hacia la nota de crédito.
 */
function creditNoteHttpFixture(): array
{
    $f = purchaseHttpFixture();

    $f['priceDifference'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'price_difference', 'account_id' => $f['priceDifference']->id,
    ]);

    $f['receipt'] = postReceiptHttp($f);

    return $f;
}

function invoiceHttp(array $f): void
{
    test()->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $f['receipt']->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();
}

// --- "Copiar a" → factura de compra ---

it('el "Copiar a" de una recepción sin facturar abre la bandeja apuntando a ese documento', function () {
    $f = creditNoteHttpFixture();

    $this->get(route('supplier-invoices.index', ['receipt' => $f['receipt']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/SupplierInvoices/Index')
            ->where('preselected', $f['receipt']->id)
        );
});

it('ignora el documento señalado si ya fue facturado', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $this->get(route('supplier-invoices.index', ['receipt' => $f['receipt']->id]))
        ->assertInertia(fn ($page) => $page->where('preselected', null));
});

// --- "Copiar a" → nota de crédito ---

it('el formulario de la nota muestra lo que queda por devolver', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $this->get(route('supplier-credit-notes.create', $f['receipt']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/SupplierCreditNotes/Create')
            ->has('lines', 1)
            ->where('lines.0.pending', fn ($v) => (float) $v === 10.0)
            ->where('lines.0.returned', fn ($v) => (float) $v === 0.0)
        );
});

it('no deja emitir una nota contra una recepción que todavía no se facturó', function () {
    $f = creditNoteHttpFixture();

    $this->get(route('supplier-credit-notes.create', $f['receipt']->id))
        ->assertRedirect(route('inventory-movements.show', $f['receipt']->id))
        ->assertSessionHasErrors('credit_note');
});

it('emitir la nota por HTTP saca la mercancía, cierra la puente y baja la deuda', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $this->post(route('supplier-credit-notes.store', $f['receipt']->id), [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'receipt_line_id' => $f['receipt']->lines->first()->id,
            'quantity' => 4,
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $nota = InventoryDocument::where('operation', 'purchase_return')->sole();

    $saldoPuente = JournalDetail::where('account_id', $f['grIr']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // 10 recibidas menos 4 devueltas; la bisagra vuelve a cerrar en cero.
    expect((float) $f['item']->fresh()->onHand())->toBe(6.0)
        ->and($saldoPuente)->toBe(0.0)
        ->and($nota->source_document_id)->toBe($f['receipt']->id);
});

it('la segunda nota solo puede devolver el remanente', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $payload = [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'receipt_line_id' => $f['receipt']->lines->first()->id,
            'quantity' => 8,
        ]],
    ];

    $this->post(route('supplier-credit-notes.store', $f['receipt']->id), $payload)
        ->assertSessionHasNoErrors();

    // Quedan 2: pedir 8 otra vez debe traducirse a un error de formulario.
    $this->post(route('supplier-credit-notes.store', $f['receipt']->id), $payload)
        ->assertSessionHasErrors('credit_note');

    expect(InventoryDocument::where('operation', 'purchase_return')->count())->toBe(1);
});

it('el detalle de la nota enlaza de vuelta a la recepción devuelta', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $this->post(route('supplier-credit-notes.store', $f['receipt']->id), [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [['receipt_line_id' => $f['receipt']->lines->first()->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();

    $nota = InventoryDocument::where('operation', 'purchase_return')->sole();

    $this->get(route('inventory-movements.show', $nota->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.source_document_id', $f['receipt']->id)
            ->where('operationLabel', 'Devolución al proveedor')
        );
});

it('rechaza una línea que no pertenece a la recepción', function () {
    $f = creditNoteHttpFixture();
    invoiceHttp($f);

    $otra = postReceiptHttp($f);

    $this->post(route('supplier-credit-notes.store', $f['receipt']->id), [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [['receipt_line_id' => $otra->lines->first()->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('credit_note');
});

// --- La devolución no se puede capturar suelta ---

it('el formulario manual de movimientos no ofrece la devolución al proveedor', function () {
    $f = creditNoteHttpFixture();

    $this->get(route('inventory-movements.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('operations.purchase_receipt', 'Entrada por compra (pendiente de facturar)')
            ->missing('operations.purchase_return')
            ->missing('operations.transfer')
        );
});

it('rechaza una devolución capturada a mano sin su recepción de origen', function () {
    $f = creditNoteHttpFixture();

    $payload = movementPayload($f, 'purchase_return');
    $payload['business_partner_id'] = $f['supplier']->id;

    // Sin documento de origen la nota debitaría la cuenta puente sin nada que
    // la cierre: la única entrada válida es el "Copiar a" de la recepción.
    $this->post(route('inventory-movements.store'), $payload)
        ->assertSessionHasErrors('operation');
});
