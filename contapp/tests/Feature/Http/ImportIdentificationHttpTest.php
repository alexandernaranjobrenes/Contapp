<?php

use App\Domains\Inventory\Models\ImportCostDocument;
use App\Domains\Inventory\Models\InventoryDocument;

// importReceiptHttp() vive en tests/Pest.php: la comparten los tests de
// costos de importación.

it('una entrada se puede marcar como importación con sus datos de aduana', function () {
    $f = importCostHttpFixture();

    $receipt = importReceiptHttp($f);

    expect($receipt->is_import)->toBeTrue()
        ->and($receipt->customs_declaration)->toBe('005-2026-123456')
        ->and($receipt->customs_office)->toBe('caldera')
        ->and($receipt->customsOfficeLabel())->toBe('Caldera')
        ->and($receipt->origin_country)->toBe('China');
});

it('una entrada por compra normal NO queda marcada como importación', function () {
    $f = importCostHttpFixture();

    $receipt = postReceiptHttp($f);

    expect($receipt->is_import)->toBeFalse()
        ->and($receipt->customs_declaration)->toBeNull();
});

it('rechaza una aduana desconocida', function () {
    $f = importCostHttpFixture();

    $payload = movementPayload($f, 'purchase_receipt');
    $payload['business_partner_id'] = $f['supplier']->id;
    $payload['is_import'] = true;
    $payload['customs_office'] = 'puerto_inventado';

    $this->post(route('inventory-movements.store'), $payload)
        ->assertSessionHasErrors('customs_office');
});

it('LA REGLA: los rubros de nacionalización solo se asignan a una importación', function () {
    $f = importCostHttpFixture();
    $local = postReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $local->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 2000],
    ])->assertSessionHasErrors('import_cost');

    // La compra local no recibió el costo y el rubro sigue disponible.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0)
        ->and((float) $rubro->fresh()->allocated_amount)->toBe(0.0);
});

it('a una importación sí se le asignan', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 2000],
    ])->assertSessionHasNoErrors();

    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0)
        ->and($rubro->fresh()->status)->toBe('allocated');
});

it('la pantalla de costeo solo ofrece importaciones', function () {
    $f = importCostHttpFixture();
    postReceiptHttp($f);
    $importacion = importReceiptHttp($f);
    accrueHttp($f, 2000);

    $this->get(route('import-costs.allocation'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('receipts', 1)
            ->where('receipts.0.id', $importacion->id)
            ->where('receipts.0.customs_declaration', '005-2026-123456')
            ->where('receipts.0.customs_office', 'Caldera')
        );
});

it('el mapa del ciclo identifica la importación por su DUA', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.is_import', true)
            ->where('cycle.nodes.0.label', 'Importación')
            ->where('cycle.nodes.0.detail', fn ($v) => str_contains($v, 'DUA 005-2026-123456')
                && str_contains($v, 'Caldera'))
        );
});

it('una compra local se sigue llamando entrada por compra en el mapa', function () {
    $f = importCostHttpFixture();
    $receipt = postReceiptHttp($f);

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertInertia(fn ($page) => $page
            ->where('cycle.nodes.0.label', 'Entrada por compra')
        );
});

it('solo una entrada por compra puede ser importación', function () {
    $f = importCostHttpFixture();

    $payload = movementPayload($f, 'goods_receipt');
    $payload['is_import'] = true;

    $this->post(route('inventory-movements.store'), $payload)
        ->assertSessionHasErrors('lines');

    expect(InventoryDocument::count())->toBe(0);
});

it('la vía directa de costos sigue admitiendo compras locales', function () {
    $f = importCostHttpFixture();
    $local = postReceiptHttp($f);

    // Un flete interno sobre una compra nacional es un costo legítimo: la
    // restricción es solo para los rubros de nacionalización.
    $this->post(route('landed-costs.store'), [
        'inventory_document_id' => $local->id,
        'document_type_id' => $f['invoiceType']->id,
        'business_partner_id' => $f['agency']->id,
        'amount' => 2000,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0)
        ->and(ImportCostDocument::count())->toBe(0);
});
