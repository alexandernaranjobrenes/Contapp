<?php

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\ItemTemplateExporter;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Compañía con los catálogos que la plantilla resuelve por código.
 */
function itemImportFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    return [
        'company' => $company,
        'uom' => UnitOfMeasure::factory()->create(['company_id' => $company->id, 'code' => 'Unid']),
        'group' => ItemGroup::factory()->create(['company_id' => $company->id, 'code' => 'FERR']),
    ];
}

/**
 * @param  array<int,array<int,mixed>>  $rows
 */
function makeItemsXlsx(array $rows, ?array $headers = null): UploadedFile
{
    $headers ??= ItemTemplateExporter::HEADERS;

    $path = tempnam(sys_get_temp_dir(), 'item_import_').'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'articulos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Una fila completa en el orden de ItemTemplateExporter::HEADERS.
 */
function itemRow(array $overrides = []): array
{
    $row = array_merge([
        'codigo' => 'ART-001',
        'nombre' => 'Tornillo hexagonal',
        'grupo' => 'FERR',
        'unidad' => 'Unid',
        'codigo_barras' => '',
        'es_inventario' => 'Sí',
        'es_venta' => 'Sí',
        'es_compra' => 'Sí',
        'lleva_lotes' => 'No',
        'minimo' => 10,
        'maximo' => 50,
        'cabys' => '2310110000000',
        'unidad_hacienda' => 'Unid',
        'tarifa_iva' => '08',
        'impuesto' => '',
        'activo' => 'Sí',
    ], $overrides);

    return array_map(fn (string $key) => $row[$key], ItemTemplateExporter::HEADERS);
}

function importItems(array $rows, ?array $headers = null)
{
    return test()->post(route('items.import'), ['file' => makeItemsXlsx($rows, $headers)]);
}

// --- La plantilla ---

it('descarga la plantilla', function () {
    itemImportFixture();

    $response = $this->get(route('items.template'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('articulos.xlsx');
});

// --- El camino feliz ---

it('LA PRUEBA CENTRAL: importa artículos con todos sus datos', function () {
    $f = itemImportFixture();

    importItems([
        itemRow(),
        itemRow(['codigo' => 'ART-002', 'nombre' => 'Tuerca', 'grupo' => '', 'minimo' => '', 'maximo' => '']),
    ])->assertSessionHasNoErrors();

    $items = Item::where('company_id', $f['company']->id)->orderBy('code')->get()->keyBy('code');

    expect($items)->toHaveCount(2);

    expect($items['ART-001']->name)->toBe('Tornillo hexagonal')
        ->and($items['ART-001']->item_group_id)->toBe($f['group']->id)
        ->and($items['ART-001']->uom_id)->toBe($f['uom']->id)
        ->and((float) $items['ART-001']->minimum_stock)->toBe(10.0)
        ->and((float) $items['ART-001']->maximum_stock)->toBe(50.0)
        ->and($items['ART-001']->cabys_code)->toBe('2310110000000')
        ->and($items['ART-001']->iva_rate_code)->toBe('08')
        ->and($items['ART-001']->status)->toBe('active');

    // Sin grupo y sin niveles: los tres campos opcionales quedan vacíos, no
    // en cero ni con basura.
    expect($items['ART-002']->item_group_id)->toBeNull()
        ->and((float) $items['ART-002']->minimum_stock)->toBe(0.0)
        ->and($items['ART-002']->maximum_stock)->toBeNull();
});

it('un código que ya existe se actualiza y el resumen los cuenta por separado', function () {
    $f = itemImportFixture();

    Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-001',
        'name' => 'Nombre viejo', 'uom_id' => $f['uom']->id,
    ]);

    importItems([
        itemRow(['nombre' => 'Nombre nuevo']),
        itemRow(['codigo' => 'ART-002', 'nombre' => 'Otro']),
    ]);

    expect(Item::where('company_id', $f['company']->id)->count())->toBe(2)
        ->and(Item::where('code', 'ART-001')->sole()->name)->toBe('Nombre nuevo')
        ->and(session('success'))->toContain('1 nuevo(s) y 1 actualizado(s)');
});

it('acepta las variantes de Sí/No y toma el valor por defecto cuando la celda va vacía', function () {
    $f = itemImportFixture();

    importItems([
        itemRow(['codigo' => 'A-1', 'es_venta' => 'S', 'es_compra' => 'X', 'lleva_lotes' => '1', 'activo' => 'no']),
        itemRow(['codigo' => 'A-2', 'es_venta' => '', 'es_compra' => '', 'lleva_lotes' => '', 'activo' => '']),
    ])->assertSessionHasNoErrors();

    $items = Item::where('company_id', $f['company']->id)->get()->keyBy('code');

    expect($items['A-1']->is_sales_item)->toBeTrue()
        ->and($items['A-1']->is_purchase_item)->toBeTrue()
        ->and($items['A-1']->tracks_lots)->toBeTrue()
        ->and($items['A-1']->status)->toBe('inactive')
        // Vacío = el valor por defecto de cada columna.
        ->and($items['A-2']->is_sales_item)->toBeTrue()
        ->and($items['A-2']->tracks_lots)->toBeFalse()
        ->and($items['A-2']->status)->toBe('active');
});

it('ignora las filas en blanco que deja Excel al final del archivo', function () {
    $f = itemImportFixture();

    importItems([
        itemRow(),
        array_fill(0, count(ItemTemplateExporter::HEADERS), ''),
    ])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $f['company']->id)->count())->toBe(1);
});

// --- Todo o nada ---

it('LA PRUEBA DEL TODO-O-NADA: una sola fila mala no importa ninguna', function () {
    $f = itemImportFixture();

    // La primera fila es válida; la segunda no tiene nombre.
    importItems([
        itemRow(),
        itemRow(['codigo' => 'ART-002', 'nombre' => '']),
    ]);

    // Ni siquiera la buena: un catálogo a medio cargar deja al usuario
    // reconciliando a mano qué entró y qué no.
    expect(Item::where('company_id', $f['company']->id)->count())->toBe(0)
        ->and(session('importErrors'))->not->toBeEmpty();
});

it('rechaza el código repetido dentro del mismo archivo, diciendo en qué fila estaba', function () {
    $f = itemImportFixture();

    importItems([itemRow(), itemRow(['nombre' => 'Repetido'])]);

    expect(Item::where('company_id', $f['company']->id)->count())->toBe(0)
        ->and(implode('|', session('importErrors')))->toContain('está repetido en el archivo')
        ->and(implode('|', session('importErrors')))->toContain('fila 2');
});

it('avisa qué columnas obligatorias faltan en vez de fallar a ciegas', function () {
    itemImportFixture();

    importItems([['ART-001', 'Tornillo']], ['codigo', 'nombre']);

    expect(implode('|', session('importErrors')))
        ->toContain('le faltan estas columnas obligatorias')
        ->toContain('unidad');
});

// --- Los catálogos que se resuelven por código ---

it('dice cuál código de catálogo no existe, no solo que la fila es inválida', function () {
    itemImportFixture();

    importItems([itemRow(['unidad' => 'CAJA', 'grupo' => 'NOEXISTE'])]);

    $errors = implode('|', session('importErrors'));

    expect($errors)->toContain('la unidad de medida "CAJA" no existe')
        ->and($errors)->toContain('el grupo "NOEXISTE" no existe')
        ->and($errors)->toContain('Códigos válidos');
});

it('no resuelve catálogos de otra compañía', function () {
    $otra = App\Domains\Core\Models\Company::factory()->create();
    UnitOfMeasure::factory()->create(['company_id' => $otra->id, 'code' => 'AJENA']);

    itemImportFixture();

    importItems([itemRow(['unidad' => 'AJENA'])]);

    expect(implode('|', session('importErrors')))->toContain('la unidad de medida "AJENA" no existe');
});

it('resuelve el indicador de impuesto por su código', function () {
    $f = itemImportFixture();

    $iva = TaxRate::factory()->create([
        'company_id' => null, 'code' => 'IVA-13', 'percentage' => '13.00',
        'tax_type_id' => TaxType::factory()->create(['company_id' => null])->id,
    ]);

    importItems([itemRow(['impuesto' => 'IVA-13'])])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $f['company']->id)->sole()->tax_rate_id)->toBe($iva->id);
});

// --- Las reglas de negocio, que son las mismas que la ficha ---

it('LA PRUEBA DE LA REGLA COMPARTIDA: el archivo no puede evadir la coherencia fiscal', function () {
    $f = itemImportFixture();

    TaxRate::factory()->create([
        'company_id' => null, 'code' => 'IVA-13', 'percentage' => '13.00',
        'tax_type_id' => TaxType::factory()->create(['company_id' => null])->id,
    ]);

    // 13% interno contra tarifa 04 (4%) de Hacienda: el XML declararía un
    // porcentaje y el asiento registraría otro. La ficha ya lo impide y el
    // archivo no puede ser la puerta de atrás.
    importItems([itemRow(['impuesto' => 'IVA-13', 'tarifa_iva' => '04'])]);

    expect(Item::where('company_id', $f['company']->id)->count())->toBe(0)
        ->and(implode('|', session('importErrors')))->toContain('La factura declararía un porcentaje distinto');
});

it('rechaza un CAByS que no son 13 dígitos', function () {
    itemImportFixture();

    importItems([itemRow(['cabys' => '231011'])]);

    expect(implode('|', session('importErrors')))->toContain('cabys');
});

it('rechaza una tarifa o unidad fuera del catálogo de Hacienda', function () {
    itemImportFixture();

    importItems([itemRow(['tarifa_iva' => '99', 'unidad_hacienda' => 'CAJA'])]);

    $errors = implode('|', session('importErrors'));

    expect($errors)->toContain('tarifa iva')->and($errors)->toContain('unidad hacienda');
});

it('rechaza un máximo por debajo del mínimo', function () {
    itemImportFixture();

    importItems([itemRow(['minimo' => 50, 'maximo' => 10])]);

    expect(implode('|', session('importErrors')))->toContain('no puede ser menor que el mínimo');
});

it('un servicio no lleva lotes ni niveles de reposición', function () {
    $f = itemImportFixture();

    importItems([itemRow(['es_inventario' => 'No', 'lleva_lotes' => 'Sí', 'minimo' => 10, 'maximo' => 50])])
        ->assertSessionHasNoErrors();

    $item = Item::where('company_id', $f['company']->id)->sole();

    // No hay existencia que rastrear ni que reponer, así que los tres se
    // fuerzan igual que en la ficha en vez de guardarse como vinieron.
    expect($item->is_inventory_item)->toBeFalse()
        ->and($item->tracks_lots)->toBeFalse()
        ->and((float) $item->minimum_stock)->toBe(0.0)
        ->and($item->maximum_stock)->toBeNull();
});

it('LA OTRA PRUEBA DE LA PUERTA DE ATRÁS: no convierte en servicio un artículo con existencias', function () {
    $f = itemImportFixture();

    $item = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-001',
        'uom_id' => $f['uom']->id, 'is_inventory_item' => true,
    ]);

    ItemWarehouse::factory()->create([
        'item_id' => $item->id,
        'warehouse_id' => Warehouse::factory()->create(['company_id' => $f['company']->id])->id,
        'on_hand' => '3.000000',
    ]);

    importItems([itemRow(['es_inventario' => 'No'])]);

    expect(implode('|', session('importErrors')))->toContain('todavía tiene existencias')
        ->and($item->fresh()->is_inventory_item)->toBeTrue();
});

// --- La frontera con el costo ---

it('LA PRUEBA DE LA FRONTERA: el archivo no puede traer existencia ni costo', function () {
    $f = itemImportFixture();

    // Aunque alguien agregue columnas a mano al archivo, se ignoran: el
    // costo lo mantiene el motor de movimientos porque cada cambio tiene que
    // generar su asiento, y una existencia importada sería inventario sin
    // contrapartida contable.
    $headers = [...ItemTemplateExporter::HEADERS, 'existencia', 'costo_promedio'];

    importItems([[...itemRow(), 999, 12345]], $headers)->assertSessionHasNoErrors();

    $item = Item::where('company_id', $f['company']->id)->sole();

    expect((float) $item->avg_cost_local)->toBe(0.0)
        ->and((float) $item->onHand())->toBe(0.0);
});

// --- Entradas malas ---

it('rechaza un archivo que no es xlsx', function () {
    itemImportFixture();

    $this->post(route('items.import'), [
        'file' => UploadedFile::fake()->create('articulos.csv', 10, 'text/csv'),
    ])->assertSessionHasErrors('file');
});

it('avisa cuando el archivo no tiene filas con datos', function () {
    itemImportFixture();

    importItems([]);

    expect(implode('|', session('importErrors')))->toContain('no tiene filas con datos');
});
