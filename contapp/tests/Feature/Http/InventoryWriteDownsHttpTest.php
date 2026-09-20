<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryWriteDown;

function writeDownHttpFixture(): array
{
    $f = movementFixture();

    foreach (['write_down_allowance' => 'asset', 'write_down_expense' => 'expense'] as $category => $type) {
        $account = ChartOfAccount::factory()->create([
            'company_id' => $f['company']->id,
            'account_type' => $type,
        ]);

        GlDetermination::factory()->create([
            'company_id' => $f['company']->id,
            'scope_level' => 'company',
            'scope_id' => null,
            'category' => $category,
            'account_id' => $account->id,
        ]);
    }

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    return $f;
}

function postWriteDown(array $f, $nrvUnit)
{
    return test()->post(route('inventory-write-downs.store'), [
        'as_of' => now()->format('Y-m-d'),
        'lines' => [['item_id' => $f['item']->id, 'nrv_unit' => $nrvUnit]],
    ]);
}

it('la pantalla de avalúo trae el costo y lo ya estimado de cada artículo', function () {
    $f = writeDownHttpFixture();

    $this->get(route('inventory-write-downs.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/WriteDowns/Create')
            ->has('items', 1)
            ->where('items.0.code', 'ART-1')
            ->where('items.0.cost_value_local', fn ($v) => (float) $v === 10000.0)
            ->where('items.0.current_allowance', fn ($v) => (float) $v === 0.0)
        );
});

it('contabiliza un avalúo por HTTP y redirige a su detalle', function () {
    $f = writeDownHttpFixture();

    postWriteDown($f, 600)->assertSessionHasNoErrors()->assertRedirect();

    $wd = InventoryWriteDown::sole();

    expect((float) $wd->lines[0]->movement_local)->toBe(4000.0);
});

it('la pantalla de avalúo ya muestra lo estimado en una segunda vuelta', function () {
    $f = writeDownHttpFixture();

    postWriteDown($f, 600)->assertSessionHasNoErrors();

    $this->get(route('inventory-write-downs.create'))
        ->assertInertia(fn ($page) => $page->where('items.0.current_allowance', fn ($v) => (float) $v === 4000.0));
});

it('un avalúo sin efecto se traduce a error de formulario, no a una excepción', function () {
    $f = writeDownHttpFixture();

    // VNR por encima del costo: no hay deterioro que reconocer.
    postWriteDown($f, 5000)->assertSessionHasErrors('lines');

    expect(InventoryWriteDown::count())->toBe(0);
});

it('rechaza un artículo de otra compañía', function () {
    writeDownHttpFixture();

    $ajeno = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => App\Domains\Core\Models\Company::factory()->create()->id,
    ]);

    $this->post(route('inventory-write-downs.store'), [
        'as_of' => now()->format('Y-m-d'),
        'lines' => [['item_id' => $ajeno->id, 'nrv_unit' => 10]],
    ])->assertSessionHasErrors('lines.0.item_id');
});

it('rechaza un VNR negativo en la validación del formulario', function () {
    $f = writeDownHttpFixture();

    postWriteDown($f, -1)->assertSessionHasErrors('lines.0.nrv_unit');
});

it('el listado muestra el efecto neto de cada avalúo', function () {
    $f = writeDownHttpFixture();

    postWriteDown($f, 600)->assertSessionHasNoErrors();

    $this->get(route('inventory-write-downs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/WriteDowns/Index')
            ->has('writeDowns', 1)
            ->where('writeDowns.0.total_movement', fn ($v) => (float) $v === 4000.0)
        );
});

it('el detalle muestra la reversión con signo negativo', function () {
    $f = writeDownHttpFixture();

    postWriteDown($f, 600)->assertSessionHasNoErrors();
    postWriteDown($f, 900)->assertSessionHasNoErrors();

    $reversion = InventoryWriteDown::orderByDesc('id')->first();

    $this->get(route('inventory-write-downs.show', $reversion->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/WriteDowns/Show')
            ->where('lines.0.movement_local', fn ($v) => (float) $v === -3000.0)
            ->where('lines.0.previous_allowance_local', fn ($v) => (float) $v === 4000.0)
        );
});
