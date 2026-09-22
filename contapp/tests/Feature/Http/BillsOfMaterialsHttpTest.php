<?php

use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\UnitOfMeasure;

function bomHttpFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $make = fn (string $code) => Item::factory()->create([
        'company_id' => $company->id, 'code' => $code, 'uom_id' => $uom->id, 'is_inventory_item' => true,
    ]);

    return [
        'company' => $company,
        'product' => $make('PT-1'),
        'a' => $make('MP-A'),
        'b' => $make('MP-B'),
    ];
}

function makeBom(array $f, array $attributes = []): BillOfMaterial
{
    return BillOfMaterial::factory()->create(array_merge([
        'company_id' => $f['company']->id,
        'item_id' => $f['product']->id,
    ], $attributes));
}

// --- La receta ---

it('crea una receta', function () {
    $f = bomHttpFixture();

    $this->post(route('bills-of-materials.store'), [
        'code' => 'BOM-1', 'name' => 'Fórmula estándar', 'item_id' => $f['product']->id,
        'output_quantity' => 100, 'is_default' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $bom = BillOfMaterial::where('company_id', $f['company']->id)->sole();

    expect($bom->code)->toBe('BOM-1')
        ->and((float) $bom->output_quantity)->toBe(100.0)
        ->and($bom->is_default)->toBeTrue();
});

it('rechaza una receta que rinde cero', function () {
    $f = bomHttpFixture();

    // Sería una división por cero al explotar.
    $this->post(route('bills-of-materials.store'), [
        'code' => 'BOM-0', 'name' => 'Imposible', 'item_id' => $f['product']->id,
        'output_quantity' => 0, 'status' => 'active',
    ])->assertSessionHasErrors('output_quantity');
});

it('rechaza fabricar un servicio', function () {
    $f = bomHttpFixture();

    $servicio = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'SERV', 'is_inventory_item' => false,
    ]);

    $this->post(route('bills-of-materials.store'), [
        'code' => 'BOM-S', 'name' => 'De un servicio', 'item_id' => $servicio->id,
        'output_quantity' => 1, 'status' => 'active',
    ])->assertSessionHasErrors('item_id');
});

it('LA PRUEBA DE LA PREDETERMINADA: es por producto, no por compañía', function () {
    $f = bomHttpFixture();

    $deOtroProducto = makeBom($f, ['code' => 'BOM-B', 'item_id' => $f['b']->id, 'is_default' => true]);
    $mismaDelProducto = makeBom($f, ['code' => 'BOM-1', 'is_default' => true]);

    // Marcar otra del MISMO producto desmarca la anterior...
    $this->post(route('bills-of-materials.store'), [
        'code' => 'BOM-2', 'name' => 'Fórmula de invierno', 'item_id' => $f['product']->id,
        'output_quantity' => 1, 'is_default' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($mismaDelProducto->fresh()->is_default)->toBeFalse()
        // ...pero la de OTRO producto no se toca: cada artículo tiene la
        // suya.
        ->and($deOtroProducto->fresh()->is_default)->toBeTrue();
});

it('no deja eliminar una receta que ya se usó en una orden', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    ProductionOrder::factory()->create([
        'company_id' => $f['company']->id,
        'item_id' => $f['product']->id,
        'bill_of_material_id' => $bom->id,
    ]);

    // El histórico tiene que poder decir con qué se fabricó cada tanda.
    $this->delete(route('bills-of-materials.destroy', $bom->id))
        ->assertSessionHasErrors('bill_of_material');

    expect(BillOfMaterial::find($bom->id))->not->toBeNull();
});

it('solo lista las recetas de la compañía activa', function () {
    $f = bomHttpFixture();
    makeBom($f, ['code' => 'PROPIA']);

    $otra = App\Domains\Core\Models\Company::factory()->create();
    BillOfMaterial::factory()->create([
        'company_id' => $otra->id,
        'item_id' => Item::factory()->create(['company_id' => $otra->id])->id,
    ]);

    $this->get(route('bills-of-materials.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/BillsOfMaterials/Index')
            ->has('billsOfMaterials', 1)
            ->where('billsOfMaterials.0.code', 'PROPIA')
        );
});

// --- Los componentes ---

it('guarda los componentes de la receta', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1', 'output_quantity' => 10]);

    $this->put(route('bills-of-materials.lines.update', $bom->id), [
        'lines' => [
            ['component_item_id' => $f['a']->id, 'quantity' => 3.2, 'scrap_percentage' => 5],
            ['component_item_id' => $f['b']->id, 'quantity' => 5],
        ],
    ])->assertSessionHasNoErrors();

    $lines = BillOfMaterialLine::where('bill_of_material_id', $bom->id)->get()->keyBy('component_item_id');

    expect($lines)->toHaveCount(2)
        ->and((float) $lines[$f['a']->id]->quantity)->toBe(3.2)
        ->and((float) $lines[$f['a']->id]->scrap_percentage)->toBe(5.0)
        ->and((float) $lines[$f['b']->id]->scrap_percentage)->toBe(0.0);
});

it('las líneas que ya no vienen se borran', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $f['a']->id, 'quantity' => 1,
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $f['b']->id, 'quantity' => 1,
    ]);

    // La receta es lo que se guardó, no lo que se guardó alguna vez.
    $this->put(route('bills-of-materials.lines.update', $bom->id), [
        'lines' => [['component_item_id' => $f['a']->id, 'quantity' => 2]],
    ])->assertSessionHasNoErrors();

    expect(BillOfMaterialLine::where('bill_of_material_id', $bom->id)->pluck('component_item_id')->all())
        ->toBe([$f['a']->id]);
});

it('vaciar la receta por completo es válido', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $f['a']->id, 'quantity' => 1,
    ]);

    $this->put(route('bills-of-materials.lines.update', $bom->id), ['lines' => []])
        ->assertSessionHasNoErrors();

    expect(BillOfMaterialLine::where('bill_of_material_id', $bom->id)->count())->toBe(0);
});

it('LA PRUEBA DEL CICLO: el servidor rechaza una receta que se contiene a sí misma', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    $this->put(route('bills-of-materials.lines.update', $bom->id), [
        'lines' => [['component_item_id' => $f['product']->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('lines');

    expect(BillOfMaterialLine::where('bill_of_material_id', $bom->id)->count())->toBe(0);
});

it('rechaza el mismo componente dos veces', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    // Se emitiría el doble sin que nadie lo note.
    $this->put(route('bills-of-materials.lines.update', $bom->id), [
        'lines' => [
            ['component_item_id' => $f['a']->id, 'quantity' => 1],
            ['component_item_id' => $f['a']->id, 'quantity' => 2],
        ],
    ])->assertSessionHasErrors('lines');

    expect(BillOfMaterialLine::where('bill_of_material_id', $bom->id)->count())->toBe(0);
});

it('rechaza un componente de otra compañía', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    $otra = App\Domains\Core\Models\Company::factory()->create();
    $ajeno = Item::factory()->create(['company_id' => $otra->id]);

    $this->put(route('bills-of-materials.lines.update', $bom->id), [
        'lines' => [['component_item_id' => $ajeno->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('lines.0.component_item_id');
});

it('la pantalla de componentes muestra la explosión del lote', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1', 'output_quantity' => 10]);

    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $f['a']->id, 'quantity' => 3.2,
    ]);

    $this->get(route('bills-of-materials.lines', $bom->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/BillsOfMaterials/Lines')
            ->has('preview', 1)
            ->where('preview.0.item_code', 'MP-A')
            ->where('preview.0.required_quantity', fn ($v) => (float) $v === 3.2)
        );
});

// --- La explosión por JSON ---

it('LA PRUEBA DEL CIERRE: la emisión pide la explosión para una cantidad y la recibe', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1', 'output_quantity' => 10]);

    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $f['a']->id, 'quantity' => 3.2,
    ]);

    $response = $this->getJson(route('bills-of-materials.explode', [$bom->id, 'quantity' => 30]));

    $response->assertOk()->assertJsonPath('bom.code', 'BOM-1');

    // La receta rinde 10 con 3,2: para 30 hacen falta 9,6.
    expect((float) $response->json('lines.0.required_quantity'))->toBe(9.6);
});

it('la explosión exige una cantidad mayor que cero', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    $response = $this->getJson(route('bills-of-materials.explode', [$bom->id, 'quantity' => 0]));

    expect($response->status())->not->toBe(200);
});

// --- La receta llega a la orden de fabricación ---

it('LA PRUEBA DEL ENLACE: la orden de fabricación guarda con qué receta se fabrica', function () {
    $f = bomHttpFixture();
    $bom = makeBom($f, ['code' => 'BOM-1']);

    $warehouse = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id,
    ]);

    $this->post(route('production-orders.store'), [
        'item_id' => $f['product']->id,
        'bill_of_material_id' => $bom->id,
        'warehouse_id' => $warehouse->id,
        'planned_quantity' => 50,
        'order_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect(ProductionOrder::where('company_id', $f['company']->id)->sole()->bill_of_material_id)
        ->toBe($bom->id);
});

it('rechaza una receta que fabrica otro artículo', function () {
    $f = bomHttpFixture();

    // Receta de MP-B usada en una orden de PT-1: la explosión sugeriría
    // emitir insumos que no tienen nada que ver.
    $ajena = makeBom($f, ['code' => 'BOM-B', 'item_id' => $f['b']->id]);

    $warehouse = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id,
    ]);

    $this->post(route('production-orders.store'), [
        'item_id' => $f['product']->id,
        'bill_of_material_id' => $ajena->id,
        'warehouse_id' => $warehouse->id,
        'planned_quantity' => 50,
        'order_date' => now()->format('Y-m-d'),
    ])->assertSessionHasErrors('bill_of_material_id');

    expect(ProductionOrder::where('company_id', $f['company']->id)->count())->toBe(0);
});

it('una orden sin receta sigue siendo válida', function () {
    $f = bomHttpFixture();

    $warehouse = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id,
    ]);

    // Una tanda puntual se fabrica sin receta y la emisión se digita, igual
    // que antes de que existieran.
    $this->post(route('production-orders.store'), [
        'item_id' => $f['product']->id,
        'warehouse_id' => $warehouse->id,
        'planned_quantity' => 10,
        'order_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect(ProductionOrder::where('company_id', $f['company']->id)->sole()->bill_of_material_id)
        ->toBeNull();
});
