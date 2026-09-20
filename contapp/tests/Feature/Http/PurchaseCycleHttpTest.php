<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;

function cycleHttpFixture(): array
{
    $f = purchaseHttpFixture();

    $f['priceDifference'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'price_difference', 'account_id' => $f['priceDifference']->id,
    ]);

    return $f;
}

function invoiceReceiptHttp(InventoryDocument $receipt, array $f): void
{
    test()->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();
}

// --- Mapa del ciclo ---

it('el mapa arranca en la entrada y marca la factura como el paso pendiente', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cycle.root_id', $receipt->id)
            ->has('cycle.nodes', 2)
            ->where('cycle.nodes.0.kind', 'receipt')
            ->where('cycle.nodes.0.current', true)
            ->where('cycle.nodes.1.kind', 'invoice')
            ->where('cycle.nodes.1.state', 'pending')
        );
});

it('el mapa muestra la factura ya emitida con su saldo pendiente de pago', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);
    invoiceReceiptHttp($receipt, $f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertInertia(fn ($page) => $page
            ->where('cycle.nodes.1.kind', 'invoice')
            ->where('cycle.nodes.1.state', 'done')
            ->where('cycle.nodes.1.amount', fn ($v) => (float) $v === 10000.0)
        );
});

it('el mapa suma las notas de crédito emitidas contra la entrada', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);
    invoiceReceiptHttp($receipt, $f);

    $this->post(route('supplier-credit-notes.store', $receipt->id), [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [['receipt_line_id' => $receipt->lines->first()->id, 'quantity' => 3]],
    ])->assertSessionHasNoErrors();

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertInertia(fn ($page) => $page
            ->has('cycle.nodes', 3)
            ->where('cycle.nodes.2.kind', 'credit_note')
            ->where('cycle.nodes.2.amount', fn ($v) => (float) $v === 3000.0)
        );
});

it('desde la nota de crédito se ve el mismo ciclo, con la nota marcada', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);
    invoiceReceiptHttp($receipt, $f);

    $this->post(route('supplier-credit-notes.store', $receipt->id), [
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [['receipt_line_id' => $receipt->lines->first()->id, 'quantity' => 3]],
    ])->assertSessionHasNoErrors();

    $nota = InventoryDocument::where('operation', 'purchase_return')->sole();

    $this->get(route('inventory-movements.show', $nota->id))
        ->assertInertia(fn ($page) => $page
            // Misma raíz: el ciclo no cambia según desde dónde se mire.
            ->where('cycle.root_id', $receipt->id)
            ->where('cycle.nodes.0.current', false)
            ->where('cycle.nodes.2.current', true)
        );
});

it('un movimiento ajeno al ciclo de compra no trae mapa', function () {
    $f = cycleHttpFixture();

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasNoErrors();

    $entrada = InventoryDocument::where('operation', 'goods_receipt')->sole();

    $this->get(route('inventory-movements.show', $entrada->id))
        ->assertInertia(fn ($page) => $page->where('cycle', null)->where('voidable', false));
});

// --- Anulación de la entrada ---

it('la entrada sin facturar ofrece anularse', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertInertia(fn ($page) => $page->where('voidable', true));
});

it('anular por HTTP revierte inventario y contabilidad', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('inventory-movements.void', $receipt->id), [
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $saldoPuente = JournalDetail::where('account_id', $f['grIr']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect((float) $f['item']->fresh()->onHand())->toBe(0.0)
        ->and($saldoPuente)->toBe(0.0)
        ->and($receipt->fresh()->status)->toBe('voided');
});

it('la anulación aparece en el mapa y cierra la rama comercial', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('inventory-movements.void', $receipt->id), [
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertInertia(fn ($page) => $page
            // Entrada + anulación, y ningún nodo de factura pendiente: esa
            // rama ya no va a ocurrir.
            ->has('cycle.nodes', 2)
            ->where('cycle.nodes.0.state', 'voided')
            ->where('cycle.nodes.1.kind', 'void')
            ->where('voidable', false)
        );
});

it('una entrada ya facturada ya no se puede anular por HTTP', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);
    invoiceReceiptHttp($receipt, $f);

    $this->post(route('inventory-movements.void', $receipt->id), [
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('void');

    expect($receipt->fresh()->status)->toBe('posted');
});

it('la entrada anulada ya no ofrece "Copiar a" ni sale en la bandeja', function () {
    $f = cycleHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('inventory-movements.void', $receipt->id), [
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    $this->get(route('supplier-invoices.index'))
        ->assertInertia(fn ($page) => $page->has('pending', 0));

    // Y facturarla directo por POST tampoco: el servicio la rechaza.
    $this->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('invoice');
});
