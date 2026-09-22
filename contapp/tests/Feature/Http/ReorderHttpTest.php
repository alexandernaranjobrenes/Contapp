<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\PurchaseOrder;

function reorderHttpFixture($onHand = 3, $minimum = 10, $maximum = null): array
{
    $f = purchaseFixture();

    logInAsCompanyUser($f['company']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: $onHand, unitCostLocal: 100),
    ]);

    ItemWarehouse::updateOrCreate(
        ['item_id' => $f['item']->id, 'warehouse_id' => $f['warehouse']->id],
        ['minimum_stock' => $minimum, 'maximum_stock' => $maximum],
    );

    return $f;
}

it('la pantalla lista lo que está bajo mínimo con su cantidad sugerida', function () {
    $f = reorderHttpFixture(3, 10);

    $this->get(route('reorder.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Reorder/Index')
            ->has('suggestions', 1)
            ->where('suggestions.0.item_code', 'ART-1')
            ->where('suggestions.0.suggested_quantity', fn ($v) => (float) $v === 7.0)
            ->where('estimatedTotal', fn ($v) => (float) $v === 700.0)
        );
});

it('filtra por almacén', function () {
    $f = reorderHttpFixture(3, 10);

    $otro = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active',
    ]);

    $this->get(route('reorder.index', ['warehouse_id' => $otro->id]))
        ->assertInertia(fn ($page) => $page->has('suggestions', 0));
});

it('LA PRUEBA CENTRAL DE INTEGRACIÓN: de la sugerencia sale una orden que la apaga', function () {
    $f = reorderHttpFixture(3, 10);

    $this->post(route('reorder.order'), [
        'business_partner_id' => $f['supplier']->id,
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 7,
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $order = PurchaseOrder::sole();

    expect($order->description)->toContain('sugerencia')
        ->and((float) $order->lines[0]->quantity)->toBe(7.0);

    // Y el artículo desaparece de la sugerencia: lo que viene en camino ya
    // cubre el faltante. Ese es el ciclo completo.
    $this->get(route('reorder.index'))
        ->assertInertia(fn ($page) => $page->has('suggestions', 0));
});

it('la orden nacida de la sugerencia pasa por las mismas validaciones', function () {
    $f = reorderHttpFixture(3, 10);

    $cliente = App\Domains\BusinessPartners\Models\BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'client',
    ]);

    $this->post(route('reorder.order'), [
        'business_partner_id' => $cliente->id,
        'lines' => [[
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 7,
        ]],
    ])->assertSessionHasErrors('lines');

    expect(PurchaseOrder::count())->toBe(0);
});

// ── Niveles ──────────────────────────────────────────────────────────────

it('la pantalla de niveles muestra el disponible de cada almacén', function () {
    $f = reorderHttpFixture(3, 10);

    $this->get(route('reorder.levels', $f['item']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/ReorderLevels')
            ->has('rows', 1)
            ->where('rows.0.on_hand', fn ($v) => (float) $v === 3.0)
            ->where('rows.0.minimum_stock', fn ($v) => (float) $v === 10.0)
        );
});

it('guarda los niveles por HTTP', function () {
    $f = reorderHttpFixture(3, 0);

    $this->put(route('reorder.levels.update', $f['item']->id), [
        'levels' => [[
            'warehouse_id' => $f['warehouse']->id,
            'minimum_stock' => 25,
            'maximum_stock' => 100,
        ]],
    ])->assertSessionHasNoErrors();

    $stock = ItemWarehouse::where('item_id', $f['item']->id)->sole();

    expect((float) $stock->minimum_stock)->toBe(25.0)
        ->and((float) $stock->maximum_stock)->toBe(100.0);
});

it('rechaza un máximo por debajo del mínimo, que dejaría el artículo sin reponerse nunca', function () {
    $f = reorderHttpFixture(3, 0);

    $this->put(route('reorder.levels.update', $f['item']->id), [
        'levels' => [[
            'warehouse_id' => $f['warehouse']->id,
            'minimum_stock' => 50,
            'maximum_stock' => 10,
        ]],
    ])->assertSessionHasErrors('levels');

    expect((float) ItemWarehouse::where('item_id', $f['item']->id)->value('minimum_stock'))->toBe(0.0);
});

it('crea la fila de existencia si el artículo nunca se movió en ese almacén', function () {
    $f = purchaseFixture();
    logInAsCompanyUser($f['company']);

    expect(ItemWarehouse::where('item_id', $f['item']->id)->count())->toBe(0);

    $this->put(route('reorder.levels.update', $f['item']->id), [
        'levels' => [[
            'warehouse_id' => $f['warehouse']->id,
            'minimum_stock' => 5,
            'maximum_stock' => null,
        ]],
    ])->assertSessionHasNoErrors();

    expect((float) ItemWarehouse::where('item_id', $f['item']->id)->sole()->minimum_stock)->toBe(5.0);
});

it('rechaza un almacén de otra compañía', function () {
    $f = reorderHttpFixture();

    $ajeno = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => App\Domains\Core\Models\Company::factory()->create()->id,
    ]);

    $this->put(route('reorder.levels.update', $f['item']->id), [
        'levels' => [['warehouse_id' => $ajeno->id, 'minimum_stock' => 5]],
    ])->assertSessionHasErrors('levels.0.warehouse_id');
});

// --- Exportación ---

function readReorderXlsx(string $path): string
{
    $reader = new OpenSpout\Reader\XLSX\Reader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();
    unlink($path);

    return collect($rows)->flatten()->implode('|');
}

it('exporta la sugerencia a XLSX', function () {
    reorderHttpFixture(3, 10);

    $response = $this->get(route('reorder.export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('sugerencia-de-compra.xlsx');
});

it('exporta la sugerencia a PDF', function () {
    reorderHttpFixture(3, 10);

    $response = $this->get(route('reorder.export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('sugerencia-de-compra.pdf');
});

it('LA PRUEBA DEL ARCHIVO: lleva las tres columnas que forman el disponible, no solo el resultado', function () {
    $f = reorderHttpFixture(20, 10);

    // 20 en existencia, 15 apartadas: disponible 5, bajo el mínimo de 10.
    // Quien autorice la compra tiene que poder ver POR QUÉ se pide algo de
    // lo que aparentemente hay existencia de sobra.
    ItemWarehouse::where('item_id', $f['item']->id)->update(['reserved' => 15]);

    $header = app(App\Domains\Reporting\Support\ReportHeaderFactory::class)->make(
        $f['company'], App\Models\User::factory()->create(['default_company_id' => $f['company']->id]),
        'Sugerencia de compra', 'Todos los almacenes y grupos',
    );

    $path = sys_get_temp_dir().'/reorder-'.uniqid().'.xlsx';
    app(App\Domains\Inventory\Services\ReorderSuggestionExporter::class)->writeTo(
        $path, $header, app(App\Domains\Inventory\Services\ReorderSuggestionService::class)->build($f['company'])
    );

    $flat = readReorderXlsx($path);

    expect($flat)->toContain('Disponible = existencia − apartado + en camino')
        ->and($flat)->toContain('Apartado')->toContain('En camino')->toContain('Disponible')
        ->and($flat)->toContain($f['item']->code)
        // La existencia (20), lo apartado (15) y el disponible (5).
        ->and($flat)->toContain('|20|15|0|5|')
        // Y de dónde salió el nivel, para saber dónde corregirlo.
        ->and($flat)->toContain('Del almacén');
});

it('el archivo respeta el filtro de la pantalla', function () {
    $f = reorderHttpFixture(3, 10);

    $otro = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM-OTRO',
    ]);

    // Filtrando por un almacén sin nada bajo mínimo, el archivo tiene que
    // salir vacío: un export que ignora los filtros y trae todo el catálogo
    // es peor que no tenerlo, porque nadie lo revisa.
    $header = app(App\Domains\Reporting\Support\ReportHeaderFactory::class)->make(
        $f['company'], App\Models\User::factory()->create(['default_company_id' => $f['company']->id]),
        'Sugerencia de compra', 'Almacén: ALM-OTRO',
    );

    $path = sys_get_temp_dir().'/reorder-'.uniqid().'.xlsx';
    app(App\Domains\Inventory\Services\ReorderSuggestionExporter::class)->writeTo(
        $path, $header,
        app(App\Domains\Inventory\Services\ReorderSuggestionService::class)->build($f['company'], $otro->id)
    );

    expect(readReorderXlsx($path))->not->toContain($f['item']->code);
});

it('el PDF no revienta cuando no hay nada que comprar', function () {
    $f = reorderHttpFixture(50, 10);

    $response = $this->get(route('reorder.export-pdf'));

    $response->assertOk();
});
