<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;

/**
 * El listado de artículos y el de movimientos traían todo (o los últimos 200
 * con un limit duro, que es peor: la información dejaba de existir para la
 * pantalla sin avisar). Estos tests fijan que ahora se pagine y que nada
 * quede inalcanzable.
 */
function paginationFixture(): array
{
    return movementFixture();
}

it('el listado de artículos pagina en vez de traerlos todos', function () {
    $f = paginationFixture();

    // 60 artículos: más de una página de 50.
    Item::factory()->count(59)->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
    ]);

    $this->get(route('items.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/Index')
            ->has('items.data', 50)
            ->where('items.total', 60)
            ->has('items.links')
        );
});

it('la segunda página trae el resto y no repite', function () {
    $f = paginationFixture();

    Item::factory()->count(59)->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
    ]);

    $primera = $this->get(route('items.index'))->viewData('page')['props']['items']['data'];
    $segunda = $this->get(route('items.index', ['page' => 2]))->viewData('page')['props']['items']['data'];

    $idsPrimera = collect($primera)->pluck('id');
    $idsSegunda = collect($segunda)->pluck('id');

    expect($idsSegunda)->toHaveCount(10)
        ->and($idsPrimera->intersect($idsSegunda))->toBeEmpty();
});

it('la búsqueda de artículos corre en el servidor, no sobre la página visible', function () {
    $f = paginationFixture();

    Item::factory()->count(59)->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
    ]);

    // Un artículo que por orden de código cae fuera de la primera página.
    Item::factory()->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
        'code' => 'ZZZ-BUSCAME',
        'name' => 'Artículo escondido',
    ]);

    $this->get(route('items.index', ['search' => 'BUSCAME']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.code', 'ZZZ-BUSCAME')
        );
});

it('la búsqueda de artículos también encuentra por nombre', function () {
    $f = paginationFixture();

    Item::factory()->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
        'code' => 'X-1',
        'name' => 'Tornillo hexagonal',
    ]);

    $this->get(route('items.index', ['search' => 'hexagonal']))
        ->assertInertia(fn ($page) => $page->has('items.data', 1));
});

it('filtra artículos por grupo y por estado', function () {
    $f = paginationFixture();

    $grupo = ItemGroup::factory()->create(['company_id' => $f['company']->id]);

    Item::factory()->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
        'item_group_id' => $grupo->id,
    ]);

    Item::factory()->create([
        'company_id' => $f['company']->id,
        'uom_id' => $f['item']->uom_id,
        'status' => 'inactive',
    ]);

    $this->get(route('items.index', ['item_group_id' => $grupo->id]))
        ->assertInertia(fn ($page) => $page->has('items.data', 1));

    $this->get(route('items.index', ['status' => 'inactive']))
        ->assertInertia(fn ($page) => $page->has('items.data', 1));
});

it('el listado de movimientos pagina', function () {
    $f = paginationFixture();

    for ($i = 0; $i < 3; $i++) {
        postMovement($f, 'goods_receipt', [
            new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, unitCostLocal: 100),
        ]);
    }

    $this->get(route('inventory-movements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Movements/Index')
            ->has('documents.data', 3)
            ->where('documents.total', 3)
            ->has('documents.links')
        );
});

it('filtra movimientos por operación', function () {
    $f = paginationFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1),
    ]);

    $this->get(route('inventory-movements.index', ['operation' => 'goods_issue']))
        ->assertInertia(fn ($page) => $page
            ->has('documents.data', 1)
            ->where('documents.data.0.operation', 'goods_issue')
        );
});

it('filtra movimientos por rango de fechas', function () {
    $f = paginationFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    $this->get(route('inventory-movements.index', ['from' => now()->addDay()->format('Y-m-d')]))
        ->assertInertia(fn ($page) => $page->has('documents.data', 0));

    $this->get(route('inventory-movements.index', ['to' => now()->format('Y-m-d')]))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1));
});

it('rechaza una operación inexistente como filtro', function () {
    paginationFixture();

    $this->get(route('inventory-movements.index', ['operation' => 'inventado']))
        ->assertSessionHasErrors('operation');
});
