<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidImportCostException;
use App\Domains\Inventory\Exceptions\InvalidLandedCostException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\ImportCostAllocation;
use App\Domains\Inventory\Models\ImportCostDocument;
use App\Domains\Inventory\Models\LandedCostDocument;
use App\Domains\Inventory\Services\PostImportCostService;
use App\Domains\Inventory\Services\PostLandedCostService;

/**
 * Compra importada ya recibida (100 u a ₡1.000) más la transitoria de costos
 * por asignar y la agencia aduanal que factura la nacionalización.
 */
function importCostFixture(): array
{
    $f = purchaseFixture();

    $f['accounts']['import_clearing'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'liability',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'landed_cost_clearing', 'account_id' => $f['accounts']['import_clearing']->id,
    ]);

    $f['accounts']['price_difference'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'price_difference', 'account_id' => $f['accounts']['price_difference']->id,
    ]);

    $f['agency'] = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'AG-001', 'name' => 'Agencia Aduanal', 'type' => 'supplier',
        'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $f['company']->id])->id,
    ]);

    $f['receipt'] = postPurchaseReceipt($f, quantity: 100, unitCost: 1000, import: true);

    return $f;
}

function accrue(array $f, $amount = 20000, array $overrides = []): ImportCostDocument
{
    return app(PostImportCostService::class)->accrue(
        company: $f['company'],
        documentType: $f['invoiceType'],
        businessPartnerId: $overrides['partnerId'] ?? $f['agency']->id,
        concept: $overrides['concept'] ?? 'flete',
        amount: $amount,
        documentDate: now(),
        postingDate: now(),
        dueDate: now()->addDays(30),
    );
}

function allocate(array $f, array $accruals, array $overrides = []): LandedCostDocument
{
    return app(PostLandedCostService::class)->post(
        company: $f['company'],
        documentType: $f['invoiceType'],
        receipt: $overrides['receipt'] ?? $f['receipt'],
        businessPartnerId: null,
        amount: 0,
        documentDate: now(),
        postingDate: now(),
        accruals: $accruals,
    );
}

// --- Fase 1: acumular ---

it('LA FASE 1: el rubro debita la transitoria y abre la deuda con la agencia', function () {
    $f = importCostFixture();

    $rubro = accrue($f, 20000);
    $entry = JournalEntry::find($rubro->journal_entry_id);

    expect((float) $entry->details->firstWhere('account_id', $f['accounts']['import_clearing']->id)->debit_local)->toBe(20000.0)
        ->and((float) $entry->details->firstWhere('account_id', $f['agency']->gl_account_id)->credit_local)->toBe(20000.0)
        ->and($rubro->status)->toBe('pending');
});

it('el rubro abre partida en cuentas por pagar con su vencimiento', function () {
    $f = importCostFixture();

    accrue($f, 20000);

    $partida = BpOpenItem::whereHas('businessPartner', fn ($q) => $q->where('id', $f['agency']->id))->sole();

    expect((float) $partida->balance)->toBe(20000.0)
        ->and($partida->status)->toBe('open');
});

it('acumular NO toca el inventario todavía', function () {
    $f = importCostFixture();

    accrue($f, 20000);

    // El costo existe y se debe, pero aún no tiene mercancía a la cual sumarse.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0);
});

it('rechaza un concepto desconocido', function () {
    $f = importCostFixture();

    accrue($f, 1000, ['concept' => 'sobornos']);
})->throws(InvalidImportCostException::class);

it('rechaza un socio que no es proveedor', function () {
    $f = importCostFixture();

    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'CL-9', 'type' => 'client',
        'gl_account_id' => $f['accounts']['payable']->id,
    ]);

    accrue($f, 1000, ['partnerId' => $cliente->id]);
})->throws(InvalidImportCostException::class);

// --- Fase 2: asignar a una importación ---

it('LA FASE 2: asignar el rubro lo capitaliza al artículo', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 20000]);

    // 100 u a ₡1.000 + ₡20.000 de flete = ₡1.200 por unidad.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0);
});

it('LA PRUEBA CENTRAL: la transitoria vuelve a cero al asignar todo', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 20000]);

    $saldo = JournalDetail::where('account_id', $f['accounts']['import_clearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($saldo)->toBe(0.0);
});

it('asignar NO vuelve a abrir deuda con nadie', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 20000]);

    // La única partida sigue siendo la que abrió el rubro al acumularse.
    expect(BpOpenItem::whereHas('businessPartner', fn ($q) => $q->where('id', $f['agency']->id))->count())->toBe(1);
});

it('varios rubros de distintos proveedores se asignan a la misma importación', function () {
    $f = importCostFixture();

    $naviera = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'NAV-1', 'type' => 'supplier',
        'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $f['company']->id])->id,
    ]);

    $flete = accrue($f, 15000, ['partnerId' => $naviera->id, 'concept' => 'flete']);
    $agencia = accrue($f, 5000, ['concept' => 'agencia']);

    allocate($f, [$flete->id => 15000, $agencia->id => 5000]);

    // ₡20.000 entre 100 u = ₡200 más por unidad.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0)
        ->and(ImportCostAllocation::count())->toBe(2);
});

it('UN RUBRO SE REPARTE entre varias importaciones', function () {
    $f = importCostFixture();
    $segunda = postPurchaseReceipt($f, quantity: 100, unitCost: 1000, import: true);

    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 12000]);

    expect($rubro->fresh()->status)->toBe('partial')
        ->and((float) $rubro->fresh()->pendingAmount())->toBe(8000.0);

    allocate($f, [$rubro->id => 8000], ['receipt' => $segunda]);

    expect($rubro->fresh()->status)->toBe('allocated')
        ->and((float) $rubro->fresh()->pendingAmount())->toBe(0.0);

    // 200 u en total y ₡20.000 repartidos: ₡100 más por unidad.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(1100.0);
});

it('rechaza asignar más de lo que al rubro le queda pendiente', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 15000]);
    allocate($f, [$rubro->id => 8000]);
})->throws(InvalidLandedCostException::class);

it('rechaza asignar un rubro cancelado', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    app(PostImportCostService::class)->cancel($f['company'], $rubro, now());

    allocate($f, [$rubro->id => 20000]);
})->throws(InvalidLandedCostException::class);

it('el costeo por rubros no anota un proveedor único, porque puede haber varios', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    $costeo = allocate($f, [$rubro->id => 20000]);

    expect($costeo->business_partner_id)->toBeNull()
        ->and((float) $costeo->amount)->toBe(20000.0);
});

it('lo que llega tarde para mercancía ya vendida va a resultados, no al costo', function () {
    $f = importCostFixture();

    // Se vende la mitad ANTES de que llegue la factura del flete.
    postMovement($f, 'goods_issue', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id, quantity: 50
        ),
    ]);

    $rubro = accrue($f, 20000);
    $costeo = allocate($f, [$rubro->id => 20000]);

    // Solo la mitad sigue en existencia: esa parte se capitaliza y el resto
    // ya no tiene sobre qué caer.
    expect((float) $costeo->capitalized_amount)->toBe(10000.0)
        ->and((float) $costeo->expensed_amount)->toBe(10000.0);
});

// --- Cancelar ---

it('cancelar un rubro sin asignar revierte su asiento', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    app(PostImportCostService::class)->cancel($f['company'], $rubro, now());

    $saldo = JournalDetail::where('account_id', $f['accounts']['import_clearing']->id)
        ->get()
        ->sum(fn ($r) => (float) $r->debit_local - (float) $r->credit_local);

    expect($rubro->fresh()->status)->toBe('cancelled')
        ->and($saldo)->toBe(0.0);
});

it('rechaza cancelar un rubro que ya entró al costo del inventario', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    allocate($f, [$rubro->id => 5000]);

    app(PostImportCostService::class)->cancel($f['company'], $rubro->fresh(), now());
})->throws(InvalidImportCostException::class);

it('ATOMICIDAD: si el costeo falla el rubro queda intacto', function () {
    $f = importCostFixture();
    $rubro = accrue($f, 20000);

    GlDetermination::where('company_id', $f['company']->id)->where('category', 'inventory')->delete();

    try {
        allocate($f, [$rubro->id => 20000]);
        $this->fail('Debió fallar al no encontrar la cuenta de inventario.');
    } catch (RuntimeException) {
        // esperado
    }

    expect((float) $rubro->fresh()->allocated_amount)->toBe(0.0)
        ->and($rubro->fresh()->status)->toBe('pending')
        ->and(ImportCostAllocation::count())->toBe(0);
});

// --- La factura directa sigue funcionando igual ---

it('la vía directa (sin rubros) sigue acreditando al proveedor y abriendo partida', function () {
    $f = importCostFixture();

    app(PostLandedCostService::class)->post(
        company: $f['company'],
        documentType: $f['invoiceType'],
        receipt: $f['receipt'],
        businessPartnerId: $f['agency']->id,
        amount: 20000,
        documentDate: now(),
        postingDate: now(),
    );

    $partida = BpOpenItem::whereHas('businessPartner', fn ($q) => $q->where('id', $f['agency']->id))->sole();

    expect((float) $partida->balance)->toBe(20000.0)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1200.0);
});
