<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\LandedCostDocument;

function purchaseHttpFixture(): array
{
    $f = movementFixture();

    $f['grIr'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'liability',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'gr_ir_clearing', 'account_id' => $f['grIr']->id,
    ]);

    $f['supplier'] = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'P-001', 'type' => 'supplier',
        'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $f['company']->id])->id,
    ]);

    $f['invoiceType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FCP', 'origin_module' => 'compras',
    ]);

    return $f;
}

function postReceiptHttp(array $f): InventoryDocument
{
    $payload = movementPayload($f, 'purchase_receipt');
    $payload['business_partner_id'] = $f['supplier']->id;

    test()->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    return InventoryDocument::where('company_id', $f['company']->id)->latest('id')->first();
}

it('exige proveedor por HTTP cuando la operación es entrada por compra', function () {
    $f = purchaseHttpFixture();

    $this->post(route('inventory-movements.store'), movementPayload($f, 'purchase_receipt'))
        ->assertSessionHasErrors('business_partner_id');

    expect(InventoryDocument::count())->toBe(0);
});

it('rechaza un socio que no es proveedor', function () {
    $f = purchaseHttpFixture();
    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'C-001', 'type' => 'client',
        'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $f['company']->id])->id,
    ]);

    $payload = movementPayload($f, 'purchase_receipt');
    $payload['business_partner_id'] = $cliente->id;

    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasErrors('business_partner_id');
});

it('la bandeja lista las recepciones pendientes con su valor recibido', function () {
    $f = purchaseHttpFixture();
    postReceiptHttp($f);

    $this->get(route('supplier-invoices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/SupplierInvoices/Index')
            ->has('pending', 1)
            // Comparación numérica: un total redondo viaja como entero en JSON.
            ->where('pending.0.total_local', fn ($value) => (float) $value === 10000.0)
        );
});

it('facturar por HTTP liquida la cuenta puente y saca la recepción de la bandeja', function () {
    $f = purchaseHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $saldoPuente = JournalDetail::where('account_id', $f['grIr']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($saldoPuente)->toBe(0.0)
        ->and($receipt->fresh()->invoice_journal_entry_id)->not->toBeNull();

    $this->get(route('supplier-invoices.index'))
        ->assertInertia(fn ($page) => $page->has('pending', 0));
});

it('traduce el intento de facturar dos veces a un error de formulario', function () {
    $f = purchaseHttpFixture();
    $receipt = postReceiptHttp($f);

    $payload = [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ];

    $this->post(route('supplier-invoices.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('supplier-invoices.store'), $payload)->assertSessionHasErrors('invoice');
});

it('rechaza un tipo de documento que no es del módulo de compras', function () {
    $f = purchaseHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['documentType']->id, // origin_module = inventario
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('document_type_id');
});

it('el detalle de la recepción muestra si sigue pendiente de facturar', function () {
    $f = purchaseHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.invoice_journal_entry_id', null)
            ->where('document.business_partner.code', 'P-001')
        );
});

// --- Costos de importación y diferencia de precio (Fase 5) ---

function landedCostHttpFixture(): array
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

it('aplica un costo de importación por HTTP y lo capitaliza al artículo', function () {
    $f = landedCostHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('landed-costs.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'business_partner_id' => $f['supplier']->id,
        'amount' => 2000,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Flete',
    ])->assertSessionHasNoErrors();

    $document = LandedCostDocument::where('company_id', $f['company']->id)->sole();

    expect((float) $document->capitalized_amount)->toBe(2000.0)
        // 10 u a ₡1.000 + ₡2.000 de flete = ₡1.200/u
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0);
});

it('rechaza un monto de costo en cero', function () {
    $f = landedCostHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('landed-costs.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'business_partner_id' => $f['supplier']->id,
        'amount' => 0,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('amount');
});

it('la pantalla lista las recepciones y los costos ya aplicados', function () {
    $f = landedCostHttpFixture();
    postReceiptHttp($f);

    $this->get(route('landed-costs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/LandedCosts/Index')
            ->has('receipts', 1)
            ->has('documents', 0)
            ->where('receipts.0.total_local', fn ($value) => (float) $value === 10000.0)
        );
});

it('facturar con un neto distinto al recibido capitaliza la diferencia', function () {
    $f = landedCostHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'net_amount' => 11000,
    ])->assertSessionHasNoErrors();

    // ₡11.000 / 10 u = ₡1.100
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1100.0);
});

it('rechaza un neto facturado en cero', function () {
    $f = landedCostHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->post(route('supplier-invoices.store'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'net_amount' => 0,
    ])->assertSessionHasErrors('net_amount');
});
