<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Services\ItemBarcodeService;

/*
|--------------------------------------------------------------------------
| Códigos de barras de los artículos
|--------------------------------------------------------------------------
|
| ItemBarcodeService: generar uno interno (EAN-13 con prefijo 200), uno por
| artículo en la compañía, solo caracteres que se pueden dibujar, y las
| etiquetas para imprimir. El dibujo y la cámara son del navegador.
|
*/

it('calcula el dígito verificador de EAN-13, EAN-8 y UPC-A', function () {
    expect(ItemBarcodeService::checkDigit('750123456789'))->toBe(3)
        ->and(ItemBarcodeService::checkDigit('9638507'))->toBe(4)
        ->and(ItemBarcodeService::checkDigit('03600029145'))->toBe(2);
});

it('genera el siguiente EAN-13 interno de la compañía, sin mirar los de otras', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->getJson(route('items.next-barcode'))->assertOk()->assertJsonPath('barcode', '2000000000015');

    $withCheck = fn (string $base) => $base.ItemBarcodeService::checkDigit($base);
    Item::factory()->create(['company_id' => $company->id, 'barcode' => $withCheck('200000000001')]);
    Item::factory()->create(['company_id' => $company->id, 'barcode' => $withCheck('200000000004')]);
    // Uno de otra compañía, más alto, no cuenta.
    Item::factory()->create(['company_id' => Company::factory()->create()->id, 'barcode' => $withCheck('200000000900')]);

    // El que sigue al último de la serie; y es un EAN-13 válido.
    $this->getJson(route('items.next-barcode'))->assertJsonPath('barcode', $withCheck('200000000005'));

    // Generar no guarda nada: es una propuesta para el formulario.
    expect(Item::where('company_id', $company->id)->count())->toBe(2);
});

it('un código de barras es de un solo artículo de la compañía, al crear y al editar', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    $rice = Item::factory()->create(['company_id' => $company->id, 'uom_id' => $uom->id, 'code' => 'A-1', 'name' => 'Arroz', 'barcode' => '7501234567893']);

    $this->post(route('items.store'), [
        'code' => 'A-2', 'name' => 'Frijol', 'uom_id' => $uom->id, 'barcode' => '7501234567893', 'status' => 'active',
    ])->assertSessionHasErrors(['barcode' => 'El código de barras 7501234567893 ya lo tiene el artículo A-1 — Arroz.']);
    expect(Item::where('code', 'A-2')->exists())->toBeFalse();

    // El mismo artículo lo conserva al editar.
    $this->put(route('items.update', $rice->id), [
        'name' => 'Arroz blanco', 'uom_id' => $uom->id, 'barcode' => '7501234567893', 'status' => 'active', 'is_inventory_item' => true,
    ])->assertSessionHasNoErrors();
    expect($rice->fresh()->name)->toBe('Arroz blanco');

    // En otra compañía, el mismo código no choca.
    Item::factory()->create(['company_id' => Company::factory()->create()->id, 'barcode' => '7790001000012']);
    $this->post(route('items.store'), [
        'code' => 'A-3', 'name' => 'Azúcar', 'uom_id' => $uom->id, 'barcode' => '7790001000012', 'status' => 'active',
    ])->assertSessionHasNoErrors();
});

it('rechaza un código de barras con tildes o ñ: no se puede dibujar', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $this->post(route('items.store'), [
        'code' => 'A-1', 'name' => 'Piña', 'uom_id' => $uom->id, 'barcode' => 'PIÑA-01', 'status' => 'active',
    ])->assertSessionHasErrors(['barcode' => 'El código de barras solo admite letras sin tilde, números y símbolos comunes: con tildes o ñ no se puede dibujar.']);
});

it('el buscador de artículos también encuentra por código de barras', function () {
    ['company' => $company] = logInAsCompanyUser();
    Item::factory()->create(['company_id' => $company->id, 'code' => 'A-1', 'barcode' => '7501234567893']);
    Item::factory()->create(['company_id' => $company->id, 'code' => 'A-2']);

    $this->get(route('items.index', ['search' => '7501234567893']))
        ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.code', 'A-1'));
});

it('generar pide Lectura y escritura en Artículos; las etiquetas, Lectura, y solo de la compañía', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'A-1', 'name' => 'Arroz', 'barcode' => '7501234567893']);
    $foreign = Item::factory()->create(['company_id' => Company::factory()->create()->id]);
    app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, ['inventory.items' => 'read']);

    $this->getJson(route('items.next-barcode'))->assertForbidden();

    $this->get(route('items.labels', $item->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Items/Labels')
            ->where('item.code', 'A-1')
            ->where('item.barcode', '7501234567893')
            ->where('company.name', $company->name));

    $this->get(route('items.labels', $foreign->id))->assertNotFound();
});
