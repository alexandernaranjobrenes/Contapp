<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Exceptions\InvalidBillOfMaterialException;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\BillOfMaterialService;

/**
 * Una receta que rinde 10 unidades de producto terminado con dos insumos.
 */
function bomFixture(float $output = 10): array
{
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $make = fn (string $code) => Item::factory()->create([
        'company_id' => $company->id, 'code' => $code, 'uom_id' => $uom->id,
    ]);

    $product = $make('PT-1');
    $a = $make('MP-A');
    $b = $make('MP-B');

    $bom = BillOfMaterial::factory()->create([
        'company_id' => $company->id, 'item_id' => $product->id,
        'code' => 'BOM-1', 'output_quantity' => $output,
    ]);

    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $a->id, 'quantity' => 3.2,
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bom->id, 'component_item_id' => $b->id, 'quantity' => 5,
    ]);

    return compact('company', 'product', 'a', 'b', 'bom', 'uom');
}

function bomComponent(array $f, string $code): Item
{
    return Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => $code, 'uom_id' => $f['uom']->id,
    ]);
}

function explodeBom(array $f, $quantity, ?int $warehouseId = null)
{
    return app(BillOfMaterialService::class)->explode($f['bom'], $quantity, $warehouseId);
}

// ── La explosión ─────────────────────────────────────────────────────────

it('LA PRUEBA CENTRAL: explota la receta escalando por el rendimiento del lote', function () {
    $f = bomFixture(output: 10);

    // La receta rinde 10 con 3,2 de A. Para 30 hacen falta 9,6.
    $lines = explodeBom($f, 30)->keyBy('item_code');

    expect((float) $lines['MP-A']['required_quantity'])->toBe(9.6)
        ->and((float) $lines['MP-B']['required_quantity'])->toBe(15.0);
});

it('un lote parcial escala hacia abajo sin arrastrar redondeo', function () {
    $f = bomFixture(output: 100);

    BillOfMaterialLine::where('bill_of_material_id', $f['bom']->id)
        ->where('component_item_id', $f['a']->id)
        ->update(['quantity' => 3.2]);

    // 3,2 por cada 100 → 0,96 para 30. Guardar la receta por unidad
    // (0,032) y volver a multiplicar arrastraría el redondeo.
    expect((float) explodeBom($f, 30)->firstWhere('item_code', 'MP-A')['required_quantity'])
        ->toBe(0.96);
});

it('fabricar exactamente un lote devuelve la receta tal cual', function () {
    $f = bomFixture(output: 10);

    $lines = explodeBom($f, 10)->keyBy('item_code');

    expect((float) $lines['MP-A']['required_quantity'])->toBe(3.2)
        ->and((float) $lines['MP-B']['required_quantity'])->toBe(5.0);
});

it('LA PRUEBA DE LA MERMA: hay que emitir de más lo que el proceso pierde', function () {
    $f = bomFixture(output: 10);

    // Si de cada 100 se pierden 5, para que queden 3,2 hay que emitir 3,36.
    BillOfMaterialLine::where('bill_of_material_id', $f['bom']->id)
        ->where('component_item_id', $f['a']->id)
        ->update(['scrap_percentage' => 5]);

    // Sin esto la orden cierra con una desviación sistemática que parece un
    // error y no lo es.
    expect((float) explodeBom($f, 10)->firstWhere('item_code', 'MP-A')['required_quantity'])
        ->toBe(3.36);
});

it('una receta que rinde cero no se puede explotar', function () {
    $f = bomFixture();

    $f['bom']->update(['output_quantity' => 0]);

    // Sin esta guarda sería una división por cero al calcular el factor.
    expect(fn () => explodeBom($f, 10))
        ->toThrow(InvalidBillOfMaterialException::class, 'rinde cero unidades');
});

// ── Lo que falta para fabricar ───────────────────────────────────────────

it('avisa cuánto falta de cada componente antes de emitir', function () {
    $f = bomFixture(output: 10);

    $warehouse = Warehouse::factory()->create(['company_id' => $f['company']->id]);

    ItemWarehouse::factory()->create([
        'item_id' => $f['a']->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '2.000000',
    ]);

    // Hacen falta 3,2 y hay 2: faltan 1,2. Decirlo antes evita que el motor
    // rechace la emisión a mitad de camino.
    $lines = explodeBom($f, 10, $warehouse->id)->keyBy('item_code');

    expect((float) $lines['MP-A']['on_hand'])->toBe(2.0)
        ->and((float) $lines['MP-A']['shortage'])->toBe(1.2)
        // De B no hay nada: falta todo.
        ->and((float) $lines['MP-B']['shortage'])->toBe(5.0);
});

it('con existencia suficiente no reporta faltante', function () {
    $f = bomFixture(output: 10);

    $warehouse = Warehouse::factory()->create(['company_id' => $f['company']->id]);

    foreach ([$f['a'], $f['b']] as $component) {
        ItemWarehouse::factory()->create([
            'item_id' => $component->id, 'warehouse_id' => $warehouse->id, 'on_hand' => '100.000000',
        ]);
    }

    expect(explodeBom($f, 10, $warehouse->id)->pluck('shortage')->unique()->all())
        ->toBe(['0.000000']);
});

// ── Ciclos: la guarda que evita una receta imposible ─────────────────────

it('LA PRUEBA DEL CICLO DIRECTO: un producto no puede ser componente de sí mismo', function () {
    $f = bomFixture();

    expect(fn () => app(BillOfMaterialService::class)->assertNoCycle(
        $f['company'], $f['product']->id, [$f['a']->id, $f['product']->id]
    ))->toThrow(InvalidBillOfMaterialException::class, 'no puede ser componente de su propia receta');
});

it('LA PRUEBA DEL CICLO INDIRECTO: A lleva B y B lleva A', function () {
    $f = bomFixture();

    // La receta de MP-A lleva el producto terminado.
    $bomA = BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $f['a']->id, 'code' => 'BOM-A',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomA->id, 'component_item_id' => $f['product']->id, 'quantity' => 1,
    ]);

    // Entonces el producto no puede llevar MP-A: se fabricaría a sí mismo.
    expect(fn () => app(BillOfMaterialService::class)->assertNoCycle(
        $f['company'], $f['product']->id, [$f['a']->id]
    ))->toThrow(InvalidBillOfMaterialException::class, 'crearía un ciclo');
});

it('detecta el ciclo a tres niveles de distancia', function () {
    $f = bomFixture();

    $c = bomComponent($f, 'MP-C');

    // PT → A → C → PT. El ciclo no es visible mirando una sola receta.
    $bomA = BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $f['a']->id, 'code' => 'BOM-A',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomA->id, 'component_item_id' => $c->id, 'quantity' => 1,
    ]);

    $bomC = BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $c->id, 'code' => 'BOM-C',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomC->id, 'component_item_id' => $f['product']->id, 'quantity' => 1,
    ]);

    expect(fn () => app(BillOfMaterialService::class)->assertNoCycle(
        $f['company'], $f['product']->id, [$f['a']->id]
    ))->toThrow(InvalidBillOfMaterialException::class, 'crearía un ciclo');
});

it('una jerarquía profunda SIN ciclo se acepta', function () {
    $f = bomFixture();

    $c = bomComponent($f, 'MP-C');
    $d = bomComponent($f, 'MP-D');

    // PT → A → C → D, y ahí termina. Es un árbol legítimo de subensambles.
    $bomA = BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $f['a']->id, 'code' => 'BOM-A',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomA->id, 'component_item_id' => $c->id, 'quantity' => 1,
    ]);

    $bomC = BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $c->id, 'code' => 'BOM-C',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomC->id, 'component_item_id' => $d->id, 'quantity' => 1,
    ]);

    app(BillOfMaterialService::class)->assertNoCycle($f['company'], $f['product']->id, [$f['a']->id]);

    expect(true)->toBeTrue();
});

it('un componente compartido por dos ramas no se confunde con un ciclo', function () {
    $f = bomFixture();

    $comun = bomComponent($f, 'MP-COMUN');

    // A y B llevan el mismo insumo. Sin el conjunto de visitados, recorrerlo
    // dos veces sería trabajo repetido; y si además tuviera receta propia,
    // un recorrido ingenuo podría no terminar.
    foreach ([$f['a'], $f['b']] as $index => $intermedio) {
        $bom = BillOfMaterial::factory()->create([
            'company_id' => $f['company']->id, 'item_id' => $intermedio->id, 'code' => 'BOM-X'.$index,
        ]);
        BillOfMaterialLine::factory()->create([
            'bill_of_material_id' => $bom->id, 'component_item_id' => $comun->id, 'quantity' => 1,
        ]);
    }

    app(BillOfMaterialService::class)->assertNoCycle(
        $f['company'], $f['product']->id, [$f['a']->id, $f['b']->id]
    );

    expect(true)->toBeTrue();
});

it('el ciclo se busca solo dentro de la compañía', function () {
    $f = bomFixture();

    $otra = Company::factory()->create();
    $ajeno = Item::factory()->create(['company_id' => $otra->id]);

    $bomAjena = BillOfMaterial::factory()->create([
        'company_id' => $otra->id, 'item_id' => $ajeno->id, 'code' => 'BOM-AJENA',
    ]);
    BillOfMaterialLine::factory()->create([
        'bill_of_material_id' => $bomAjena->id, 'component_item_id' => $f['product']->id, 'quantity' => 1,
    ]);

    // Una receta de otra compañía no puede crear un ciclo acá.
    app(BillOfMaterialService::class)->assertNoCycle($f['company'], $f['product']->id, [$f['a']->id]);

    expect(true)->toBeTrue();
});

// ── Cuál receta se ofrece ────────────────────────────────────────────────

it('con una sola receta activa no hace falta marcarla como predeterminada', function () {
    $f = bomFixture();

    // Exigirlo sería burocracia para el caso normal: un producto con una
    // fórmula.
    expect(app(BillOfMaterialService::class)->defaultFor($f['company'], $f['product']->id)?->code)
        ->toBe('BOM-1');
});

it('con varias recetas se ofrece la marcada como predeterminada', function () {
    $f = bomFixture();

    BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $f['product']->id,
        'code' => 'BOM-2', 'is_default' => true,
    ]);

    expect(app(BillOfMaterialService::class)->defaultFor($f['company'], $f['product']->id)?->code)
        ->toBe('BOM-2');
});

it('con varias recetas y ninguna marcada no se elige por el sistema', function () {
    $f = bomFixture();

    BillOfMaterial::factory()->create([
        'company_id' => $f['company']->id, 'item_id' => $f['product']->id, 'code' => 'BOM-2',
    ]);

    // Adivinar cuál fórmula usar es una decisión de planta, no del sistema.
    expect(app(BillOfMaterialService::class)->defaultFor($f['company'], $f['product']->id))->toBeNull();
});

it('una receta inactiva no se ofrece', function () {
    $f = bomFixture();

    $f['bom']->update(['status' => 'inactive']);

    expect(app(BillOfMaterialService::class)->defaultFor($f['company'], $f['product']->id))->toBeNull();
});

// ── La frontera con el costeo ────────────────────────────────────────────

it('LA PRUEBA DE LA FRONTERA: la receta no dice nada del costo', function () {
    $f = bomFixture();

    $f['a']->update(['avg_cost_local' => 500]);

    $line = explodeBom($f, 10)->firstWhere('item_code', 'MP-A');

    // La explosión dice QUÉ y CUÁNTO. El costo lo pone el motor de
    // movimientos al contabilizar la emisión, al promedio vigente. Un costo
    // calculado acá sería costeo estándar, que es otro sistema.
    expect($line)->not->toHaveKey('unit_cost')
        ->and($line)->not->toHaveKey('total_cost')
        ->and($line)->toHaveKeys(['required_quantity', 'on_hand', 'shortage']);
});
