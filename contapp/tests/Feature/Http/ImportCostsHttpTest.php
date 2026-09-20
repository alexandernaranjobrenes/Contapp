<?php

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Inventory\Models\ImportCostDocument;

// importCostHttpFixture() y accrueHttp() viven en tests/Pest.php: los comparte
// el test de identificación de importaciones.

it('registrar un rubro por HTTP lo deja pendiente de asignar', function () {
    $f = importCostHttpFixture();

    $rubro = accrueHttp($f, 2000);

    expect($rubro->status)->toBe('pending')
        ->and((float) $rubro->pendingAmount())->toBe(2000.0)
        // Todavía no tocó el inventario.
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(0.0);
});

it('la bandeja muestra el saldo pendiente de la transitoria', function () {
    $f = importCostHttpFixture();
    accrueHttp($f, 2000);
    accrueHttp($f, 3000, ['concept' => 'aranceles']);

    $this->get(route('import-costs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/ImportCosts/Index')
            ->has('documents', 2)
            ->where('documents.0.status', 'pending')
        );
});

it('la pantalla de costeo lista los rubros pendientes y las importaciones', function () {
    $f = importCostHttpFixture();
    importReceiptHttp($f);
    accrueHttp($f, 2000);

    $this->get(route('import-costs.allocation'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/ImportCosts/Allocate')
            ->has('pending', 1)
            ->has('receipts', 1)
            ->where('pending.0.pending_amount', fn ($v) => (float) $v === 2000.0)
        );
});

it('asignar por HTTP capitaliza el costo y liquida la transitoria', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 2000],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $saldo = JournalDetail::where('account_id', $f['importClearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    // 10 u a ₡1.000 + ₡2.000 de flete = ₡1.200
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0)
        ->and($saldo)->toBe(0.0)
        ->and($rubro->fresh()->status)->toBe('allocated');
});

it('asignar parcialmente deja el rubro con saldo', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 800],
    ])->assertSessionHasNoErrors();

    expect($rubro->fresh()->status)->toBe('partial')
        ->and((float) $rubro->fresh()->pendingAmount())->toBe(1200.0);
});

it('traduce a error de formulario el intento de asignar de más', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 5000],
    ])->assertSessionHasErrors('import_cost');

    expect((float) $rubro->fresh()->allocated_amount)->toBe(0.0);
});

it('cancelar un rubro por HTTP revierte su asiento', function () {
    $f = importCostHttpFixture();
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.cancel', $rubro->id))->assertSessionHasNoErrors();

    $saldo = JournalDetail::where('account_id', $f['importClearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($rubro->fresh()->status)->toBe('cancelled')
        ->and($saldo)->toBe(0.0);
});

it('EL MAPA: el nodo del costeo nombra los rubros que lo componen', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);

    $flete = accrueHttp($f, 1200, ['concept' => 'flete']);
    $agencia = accrueHttp($f, 800, ['concept' => 'agencia']);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$flete->id => 1200, $agencia->id => 800],
    ])->assertSessionHasNoErrors();

    $this->get(route('inventory-movements.show', $receipt->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cycle.nodes.1.kind', 'landed_cost')
            ->where('cycle.nodes.1.detail', 'Flete internacional + Agencia aduanal')
            ->where('cycle.nodes.1.amount', fn ($v) => (float) $v === 2000.0)
        );
});

it('LA VISTA INVERSA: desde el rubro se ve a qué importación se cargó', function () {
    $f = importCostHttpFixture();
    $receipt = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    $this->post(route('import-costs.allocate'), [
        'inventory_document_id' => $receipt->id,
        'document_type_id' => $f['invoiceType']->id,
        'posting_date' => now()->format('Y-m-d'),
        'accruals' => [$rubro->id => 2000],
    ])->assertSessionHasNoErrors();

    $this->get(route('import-costs.index'))
        ->assertInertia(fn ($page) => $page
            ->has('documents.0.allocations', 1)
            ->where('documents.0.allocations.0.receipt_id', $receipt->id)
            ->where('documents.0.allocations.0.amount', fn ($v) => (float) $v === 2000.0)
        );
});

it('un rubro se reparte entre dos importaciones distintas', function () {
    $f = importCostHttpFixture();
    $primera = importReceiptHttp($f);
    $segunda = importReceiptHttp($f);
    $rubro = accrueHttp($f, 2000);

    foreach ([[$primera, 1200], [$segunda, 800]] as [$receipt, $amount]) {
        $this->post(route('import-costs.allocate'), [
            'inventory_document_id' => $receipt->id,
            'document_type_id' => $f['invoiceType']->id,
            'posting_date' => now()->format('Y-m-d'),
            'accruals' => [$rubro->id => $amount],
        ])->assertSessionHasNoErrors();
    }

    expect($rubro->fresh()->status)->toBe('allocated')
        ->and((float) $rubro->fresh()->pendingAmount())->toBe(0.0);

    // 20 u recibidas en total y ₡2.000 repartidos: ₡100 más por unidad.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1100.0);
});

it('rechaza un rubro con concepto desconocido', function () {
    $f = importCostHttpFixture();

    $this->post(route('import-costs.store'), [
        'document_type_id' => $f['invoiceType']->id,
        'business_partner_id' => $f['agency']->id,
        'concept' => 'inventado',
        'amount' => 1000,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('concept');

    expect(ImportCostDocument::count())->toBe(0);
});
