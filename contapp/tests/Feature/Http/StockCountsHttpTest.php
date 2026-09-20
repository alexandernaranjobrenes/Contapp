<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\StockCount;

function countHttpFixture(): array
{
    $f = movementFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 1000),
    ]);

    return $f;
}

function openCountHttp(array $f, array $overrides = []): StockCount
{
    test()->post(route('stock-counts.store'), array_merge([
        'document_type_id' => $f['documentType']->id,
        'cutoff_date' => now()->format('Y-m-d'),
        'warehouse_id' => $f['warehouse']->id,
        'blind' => false,
    ], $overrides))->assertSessionHasNoErrors();

    return StockCount::latest('id')->first();
}

it('abrir una toma por HTTP congela la existencia teórica', function () {
    $f = countHttpFixture();

    $count = openCountHttp($f);

    expect($count->status)->toBe('open')
        ->and((float) $count->lines->first()->theoretical_quantity)->toBe(100.0)
        // Abrir no ajusta nada todavía.
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('el listado muestra la toma con su corte y su almacén', function () {
    $f = countHttpFixture();
    openCountHttp($f);

    $this->get(route('stock-counts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/StockCounts/Index')
            ->has('counts', 1)
            ->where('counts.0.status', 'open')
            ->where('counts.0.lines_count', 1)
        );
});

it('la pantalla de alta marca los almacenes que ya tienen una toma abierta', function () {
    $f = countHttpFixture();
    openCountHttp($f);

    $this->get(route('stock-counts.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/StockCounts/Create')
            ->where('busyWarehouses', [$f['warehouse']->id])
        );
});

it('traduce a error de formulario el intento de abrir dos tomas del mismo almacén', function () {
    $f = countHttpFixture();
    openCountHttp($f);

    $this->post(route('stock-counts.store'), [
        'document_type_id' => $f['documentType']->id,
        'cutoff_date' => now()->format('Y-m-d'),
        'warehouse_id' => $f['warehouse']->id,
    ])->assertSessionHasErrors('count');

    expect(StockCount::count())->toBe(1);
});

it('el detalle muestra la línea sin contar y su teórica', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $this->get(route('stock-counts.show', $count->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/StockCounts/Show')
            ->where('count.status', 'open')
            ->has('lines', 1)
            ->where('lines.0.theoretical_quantity', fn ($v) => (float) $v === 100.0)
            ->where('lines.0.counted_quantity', null)
            ->where('lines.0.difference', null)
        );
});

it('capturar el conteo guarda la cantidad y calcula la diferencia', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $this->post(route('stock-counts.capture', $count->id), [
        'counted' => [$count->lines->first()->id => 97],
    ])->assertSessionHasNoErrors();

    $this->get(route('stock-counts.show', $count->id))
        ->assertInertia(fn ($page) => $page
            ->where('lines.0.counted_quantity', fn ($v) => (float) $v === 97.0)
            ->where('lines.0.difference', fn ($v) => (float) $v === -3.0)
            // 3 u faltantes × ₡1.000
            ->where('lines.0.difference_value', fn ($v) => (float) $v === -3000.0)
        );
});

it('cerrar por HTTP contabiliza el ajuste y baja la existencia', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $this->post(route('stock-counts.capture', $count->id), [
        'counted' => [$count->lines->first()->id => 97],
    ])->assertSessionHasNoErrors();

    $this->post(route('stock-counts.post', $count->id), ['posting_date' => now()->format('Y-m-d')])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect((float) $f['item']->fresh()->onHand())->toBe(97.0)
        ->and($count->fresh()->status)->toBe('posted')
        ->and(InventoryDocument::where('operation', 'count_adjustment')->count())->toBe(1);
});

it('traduce a error de formulario el intento de cerrar con líneas sin contar', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $this->post(route('stock-counts.post', $count->id), ['posting_date' => now()->format('Y-m-d')])
        ->assertSessionHasErrors('count');

    expect($count->fresh()->status)->toBe('open');
});

it('cancelar por HTTP libera el almacén sin ajustar nada', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $this->post(route('stock-counts.cancel', $count->id), ['reason' => 'Se suspendió'])
        ->assertSessionHasNoErrors();

    expect($count->fresh()->status)->toBe('cancelled')
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);

    // Con el almacén libre se puede abrir otra.
    expect(openCountHttp($f)->status)->toBe('open');
});

it('filtra por familia al abrir', function () {
    $f = countHttpFixture();

    $familia = ItemGroup::factory()->create(['company_id' => $f['company']->id, 'code' => 'FAM2']);
    $otro = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-7',
        'is_inventory_item' => true, 'item_group_id' => $familia->id,
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($otro->id, $f['warehouse']->id, quantity: 8, unitCostLocal: 300),
    ]);

    $count = openCountHttp($f, ['item_group_id' => $familia->id]);

    expect($count->lines)->toHaveCount(1)
        ->and($count->lines->first()->item_id)->toBe($otro->id);
});

// --- La hoja impresa ---

it('la hoja de conteo se descarga en PDF', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f);

    $response = $this->get(route('stock-counts.print', $count->id));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))
        ->toContain("toma-fisica-{$count->number}.pdf");
});

it('la hoja de un conteo a ciegas se genera igual', function () {
    $f = countHttpFixture();
    $count = openCountHttp($f, ['blind' => true]);

    expect($count->blind)->toBeTrue();

    $this->get(route('stock-counts.print', $count->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
