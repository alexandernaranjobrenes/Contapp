<?php

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

// --- Unidades de medida ---

it('solo lista las unidades de medida de la compañía activa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    UnitOfMeasure::factory()->create(['company_id' => $companyA->id, 'code' => 'UND']);
    UnitOfMeasure::factory()->create(['company_id' => $companyB->id, 'code' => 'KG']);

    logInAsCompanyUser($companyA);

    $this->get(route('units-of-measure.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/UnitsOfMeasure/Index')
            ->has('unitsOfMeasure', 1)
            ->where('unitsOfMeasure.0.code', 'UND')
        );
});

it('crea una unidad de medida y rechaza el código duplicado en la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('units-of-measure.store'), [
        'code' => 'KG', 'name' => 'Kilogramo', 'decimals' => 3, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(UnitOfMeasure::where('company_id', $company->id)->sole()->decimals)->toBe(3);

    $this->post(route('units-of-measure.store'), [
        'code' => 'KG', 'name' => 'Repetida', 'decimals' => 2, 'status' => 'active',
    ])->assertSessionHasErrors('code');

    expect(UnitOfMeasure::where('company_id', $company->id)->count())->toBe(1);
});

it('rechaza eliminar una unidad de medida en uso por un artículo', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    Item::factory()->create(['company_id' => $company->id, 'uom_id' => $uom->id]);

    $this->delete(route('units-of-measure.destroy', $uom->id))->assertSessionHasErrors('unit_of_measure');

    expect(UnitOfMeasure::find($uom->id))->not->toBeNull();
});

// --- Grupos de artículos ---

it('rechaza eliminar un grupo con artículos asignados en vez de dejar que la FK los desvincule', function () {
    ['company' => $company] = logInAsCompanyUser();
    $group = ItemGroup::factory()->create(['company_id' => $company->id]);
    $item = Item::factory()->create(['company_id' => $company->id, 'item_group_id' => $group->id]);

    $this->delete(route('item-groups.destroy', $group->id))->assertSessionHasErrors('item_group');

    expect(ItemGroup::find($group->id))->not->toBeNull()
        ->and($item->fresh()->item_group_id)->toBe($group->id);
});

it('elimina un grupo sin artículos', function () {
    ['company' => $company] = logInAsCompanyUser();
    $group = ItemGroup::factory()->create(['company_id' => $company->id]);

    $this->delete(route('item-groups.destroy', $group->id))->assertSessionHasNoErrors();

    expect(ItemGroup::find($group->id))->toBeNull();
});

// --- Almacenes ---

it('marcar un almacén por defecto desmarca al anterior', function () {
    ['company' => $company] = logInAsCompanyUser();
    $primero = Warehouse::factory()->create(['company_id' => $company->id, 'code' => 'ALM1', 'is_default' => true]);

    $this->post(route('warehouses.store'), [
        'code' => 'ALM2', 'name' => 'Segundo', 'is_default' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $segundo = Warehouse::where('company_id', $company->id)->where('code', 'ALM2')->sole();

    expect($segundo->is_default)->toBeTrue()
        ->and($primero->fresh()->is_default)->toBeFalse();
});

it('rechaza eliminar un almacén con existencias', function () {
    ['company' => $company] = logInAsCompanyUser();
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
    $item = Item::factory()->create(['company_id' => $company->id]);

    ItemWarehouse::factory()->create([
        'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '5.000000',
    ]);

    $this->delete(route('warehouses.destroy', $warehouse->id))->assertSessionHasErrors('warehouse');

    expect(Warehouse::find($warehouse->id))->not->toBeNull();
});

it('elimina un almacén cuyas filas de existencia están todas en cero', function () {
    ['company' => $company] = logInAsCompanyUser();
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
    $item = Item::factory()->create(['company_id' => $company->id]);

    ItemWarehouse::factory()->create([
        'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '0.000000',
    ]);

    $this->delete(route('warehouses.destroy', $warehouse->id))->assertSessionHasNoErrors();

    expect(Warehouse::find($warehouse->id))->toBeNull()
        ->and(ItemWarehouse::where('warehouse_id', $warehouse->id)->count())->toBe(0);
});

// --- Artículos ---

it('crea un artículo con grupo, unidad de medida e indicador de impuesto', function () {
    ['company' => $company] = logInAsCompanyUser();
    $group = ItemGroup::factory()->create(['company_id' => $company->id]);
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $this->post(route('items.store'), [
        'code' => 'ART-001',
        'name' => 'Tornillo 1/4',
        'item_group_id' => $group->id,
        'uom_id' => $uom->id,
        'is_inventory_item' => true,
        'is_sales_item' => true,
        'is_purchase_item' => true,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $item = Item::where('company_id', $company->id)->sole();

    expect($item->code)->toBe('ART-001')
        ->and($item->item_group_id)->toBe($group->id)
        ->and($item->uom_id)->toBe($uom->id)
        // Un artículo nuevo nace sin costo: lo fija la primera entrada de stock.
        ->and((float) $item->avg_cost_local)->toBe(0.0);
});

it('acepta un indicador de impuesto del catálogo nacional (company_id NULL)', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $taxType = TaxType::factory()->create(['company_id' => null]);
    $taxRate = TaxRate::factory()->create(['company_id' => null, 'tax_type_id' => $taxType->id]);

    $this->post(route('items.store'), [
        'code' => 'ART-IVA', 'name' => 'Con IVA nacional', 'uom_id' => $uom->id,
        'tax_rate_id' => $taxRate->id, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $company->id)->sole()->tax_rate_id)->toBe($taxRate->id);
});

it('rechaza una unidad de medida de otra compañía', function () {
    $otra = Company::factory()->create();
    $uomAjena = UnitOfMeasure::factory()->create(['company_id' => $otra->id]);

    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('items.store'), [
        'code' => 'ART-X', 'name' => 'Intento', 'uom_id' => $uomAjena->id, 'status' => 'active',
    ])->assertSessionHasErrors('uom_id');

    expect(Item::where('company_id', $company->id)->count())->toBe(0);
});

it('no deja convertir en servicio un artículo que todavía tiene existencias', function () {
    ['company' => $company] = logInAsCompanyUser();
    $item = Item::factory()->create(['company_id' => $company->id, 'is_inventory_item' => true]);
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

    ItemWarehouse::factory()->create([
        'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '3.000000',
    ]);

    $this->put(route('items.update', $item->id), [
        'name' => $item->name,
        'uom_id' => $item->uom_id,
        'is_inventory_item' => false,
        'status' => 'active',
    ])->assertSessionHasErrors('is_inventory_item');

    expect($item->fresh()->is_inventory_item)->toBeTrue();
});

it('rechaza eliminar un artículo con existencias', function () {
    ['company' => $company] = logInAsCompanyUser();
    $item = Item::factory()->create(['company_id' => $company->id]);
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

    ItemWarehouse::factory()->create([
        'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '1.500000',
    ]);

    $this->delete(route('items.destroy', $item->id))->assertSessionHasErrors('item');

    expect(Item::find($item->id))->not->toBeNull();
});

it('rechaza actualizar un artículo de otra compañía', function () {
    $otra = Company::factory()->create();
    $ajeno = Item::factory()->create(['company_id' => $otra->id]);

    logInAsCompanyUser();

    $this->put(route('items.update', $ajeno->id), [
        'name' => 'Intento ajeno', 'uom_id' => $ajeno->uom_id, 'status' => 'active',
    ])->assertNotFound();
});

it('expone la existencia total del artículo sumando sus almacenes', function () {
    ['company' => $company] = logInAsCompanyUser();
    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-SUM']);

    foreach (['4.000000', '6.500000'] as $onHand) {
        ItemWarehouse::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => Warehouse::factory()->create(['company_id' => $company->id])->id,
            'on_hand' => $onHand,
        ]);
    }

    $this->get(route('items.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/Index')
            // items pasó a ser un paginador cuando el listado dejó de traer
            // el catálogo entero: las filas viven en items.data.
            ->where('items.data.0.on_hand', fn ($value) => (float) $value === 10.5)
        );

    expect((float) $item->onHand())->toBe(10.5);
});
