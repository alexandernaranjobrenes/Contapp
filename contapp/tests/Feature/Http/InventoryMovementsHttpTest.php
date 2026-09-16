<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;

// Los fixtures compartidos (movementFixture, movementPayload) viven en
// tests/Pest.php: los usan también los tests de compras.

it('contabiliza una entrada por HTTP y redirige al detalle del movimiento', function () {
    $f = movementFixture();

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $document = InventoryDocument::where('company_id', $f['company']->id)->sole();

    expect($document->operation)->toBe('goods_receipt')
        ->and($document->journal_entry_id)->not->toBeNull()
        ->and(StockJournal::where('journal_entry_id', $document->journal_entry_id)->count())->toBe(1)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0);
});

it('traduce la falta de existencia a un error de formulario, sin dejar nada contabilizado', function () {
    $f = movementFixture();

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_issue', ['quantity' => 5]))
        ->assertSessionHasErrors('lines');

    expect(InventoryDocument::count())->toBe(0)
        ->and(StockJournal::count())->toBe(0);
});

it('rechaza un tipo de documento que no es del módulo de inventario', function () {
    $f = movementFixture();
    $contable = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ADD', 'origin_module' => 'contable',
    ]);

    $payload = movementPayload($f, 'goods_receipt');
    $payload['document_type_id'] = $contable->id;

    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasErrors('document_type_id');
});

it('rechaza un artículo de otra compañía', function () {
    $f = movementFixture();
    $ajeno = Item::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt', ['item_id' => $ajeno->id]))
        ->assertSessionHasErrors('lines.0.item_id');
});

it('el detalle del movimiento muestra las líneas con su kardex', function () {
    $f = movementFixture();
    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'));

    $document = InventoryDocument::where('company_id', $f['company']->id)->sole();

    $this->get(route('inventory-movements.show', $document->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Movements/Show')
            ->has('document.lines', 1)
            ->has('document.lines.0.stock_journals', 1)
        );
});

it('el kardex de un artículo lista sus movimientos y se puede filtrar por almacén', function () {
    $f = movementFixture();
    $otro = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2']);

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'));
    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt', ['warehouse_id' => $otro->id]));

    $this->get(route('items.kardex', $f['item']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Kardex/Show')->has('movements', 2));

    $this->get(route('items.kardex', ['item' => $f['item']->id, 'warehouse_id' => $otro->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements', 1)
            ->where('movements.0.warehouse_code', 'ALM2')
        );
});

// --- Matriz de determinación ---

it('crea una regla de determinación y rechaza duplicar el mismo alcance y categoría', function () {
    ['company' => $company] = logInAsCompanyUser();
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $payload = [
        'scope_level' => 'company', 'scope_id' => null,
        'category' => 'inventory', 'account_id' => $account->id,
    ];

    $this->post(route('gl-determinations.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('gl-determinations.store'), $payload)->assertSessionHasErrors('category');

    expect(GlDetermination::where('company_id', $company->id)->count())->toBe(1);
});

it('exige elegir a qué artículo/grupo/almacén aplica cuando el alcance no es la compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $this->post(route('gl-determinations.store'), [
        'scope_level' => 'item', 'scope_id' => null,
        'category' => 'inventory', 'account_id' => $account->id,
    ])->assertSessionHasErrors('scope_id');
});

it('rechaza configurar una cuenta que no acepta movimientos', function () {
    ['company' => $company] = logInAsCompanyUser();
    $noPostea = ChartOfAccount::factory()->nonPosting()->create(['company_id' => $company->id]);

    $this->post(route('gl-determinations.store'), [
        'scope_level' => 'company', 'scope_id' => null,
        'category' => 'inventory', 'account_id' => $noPostea->id,
    ])->assertSessionHasErrors('account_id');
});

it('rechaza una cuenta de otra compañía', function () {
    logInAsCompanyUser();
    $ajena = ChartOfAccount::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->post(route('gl-determinations.store'), [
        'scope_level' => 'company', 'scope_id' => null,
        'category' => 'inventory', 'account_id' => $ajena->id,
    ])->assertSessionHasErrors('account_id');
});
