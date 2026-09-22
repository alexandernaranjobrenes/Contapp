<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemSerial;

function serialHttpFixture(): array
{
    $f = purchaseFixture();
    $f['item']->update(['tracks_serials' => true]);

    logInAsCompanyUser($f['company']);

    return $f;
}

function receiveSerials(array $f, array $serials): void
{
    postMovement($f, 'goods_receipt', [
        new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: count($serials), unitCostLocal: 100, serialNumbers: $serials,
        ),
    ]);
}

it('lista las series del artículo con su estado', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1', 'S-2']);

    $this->get(route('item-serials.index', $f['item']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/Serials')
            ->has('serials.data', 2)
            ->where('serials.data.0.serial_number', 'S-1')
            ->where('serials.data.0.status', 'in_stock')
        );
});

it('LA PRUEBA DEL INVARIANTE: la pantalla muestra si las series cuadran con la existencia', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1', 'S-2', 'S-3']);

    $this->get(route('item-serials.index', $f['item']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reconciliation.serials_in_stock', 3)
            ->where('reconciliation.on_hand', fn ($v) => (float) $v === 3.0)
        );
});

it('filtra por estado', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1', 'S-2']);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, serialNumbers: ['S-1']),
    ]);

    $this->get(route('item-serials.index', [$f['item']->id, 'status' => 'issued']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('serials.data', 1)
            ->where('serials.data.0.serial_number', 'S-1')
        );
});

it('guarda la garantía y las notas', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    $this->put(route('item-serials.update', [$f['item']->id, $serial->id]), [
        'warranty_until' => '2028-01-31',
        'notes' => 'Garantía extendida del proveedor',
    ])->assertSessionHasNoErrors();

    expect($serial->fresh()->warranty_until->format('Y-m-d'))->toBe('2028-01-31')
        ->and($serial->fresh()->notes)->toBe('Garantía extendida del proveedor');
});

it('LA PRUEBA DE LA PUERTA DE ATRÁS: no se puede dar de baja una serie que sigue en existencia', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    // Marcarla acá dejaría el inventario diciendo que la tiene: una unidad
    // que se destruye tiene que salir con su asiento, igual que cualquier
    // otra baja.
    $this->post(route('item-serials.scrap', [$f['item']->id, $serial->id]))
        ->assertSessionHasErrors('serial');

    expect($serial->fresh()->status)->toBe('in_stock');
});

it('marca como dada de baja una serie que ya salió', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1']);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 1, serialNumbers: ['S-1']),
    ]);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    // Distinguir la que se vendió de la que se destruyó: la garantía las
    // trata distinto.
    $this->post(route('item-serials.scrap', [$f['item']->id, $serial->id]))
        ->assertSessionHasNoErrors();

    expect($serial->fresh()->status)->toBe('scrapped');
});

it('no deja tocar una serie de otro artículo', function () {
    $f = serialHttpFixture();
    receiveSerials($f, ['S-1']);

    $otro = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'ART-OTRO']);
    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    $this->put(route('item-serials.update', [$otro->id, $serial->id]), ['warranty_until' => '2028-01-01'])
        ->assertNotFound();
});

it('la ficha guarda que el artículo maneja series', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = App\Domains\Inventory\Models\UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $this->post(route('items.store'), [
        'code' => 'EQ-1', 'name' => 'Equipo', 'uom_id' => $uom->id,
        'is_inventory_item' => true, 'tracks_serials' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $company->id)->sole()->tracks_serials)->toBeTrue();
});

it('un servicio no puede manejar series: no hay unidad que identificar', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = App\Domains\Inventory\Models\UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $this->post(route('items.store'), [
        'code' => 'SERV-1', 'name' => 'Instalación', 'uom_id' => $uom->id,
        'is_inventory_item' => false, 'tracks_serials' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $company->id)->sole()->tracks_serials)->toBeFalse();
});
