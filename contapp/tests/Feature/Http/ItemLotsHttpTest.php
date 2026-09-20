<?php

use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\ItemLotStock;

function lotHttpFixture(): array
{
    $f = movementFixture();

    $f['item']->update(['tracks_lots' => true]);

    return $f;
}

function createLot(array $f, string $code, ?string $expiresAt = null, string $status = 'active'): ItemLot
{
    return ItemLot::create([
        'item_id' => $f['item']->id,
        'code' => $code,
        'expires_at' => $expiresAt,
        'status' => $status,
    ]);
}

// ── Catálogo de lotes ────────────────────────────────────────────────────

it('la pantalla de lotes muestra la existencia de cada uno', function () {
    $f = lotHttpFixture();
    $lot = createLot($f, 'L-001', now()->addMonths(3)->format('Y-m-d'));

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $lot->id;

    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $this->get(route('item-lots.index', $f['item']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/Lots')
            ->has('lots', 1)
            ->where('lots.0.code', 'L-001')
            ->where('lots.0.on_hand', fn ($value) => (float) $value === 10.0)
            ->where('lots.0.is_expired', false)
        );
});

it('crea un lote por HTTP', function () {
    $f = lotHttpFixture();

    $this->post(route('item-lots.store', $f['item']->id), [
        'code' => 'L-777',
        'expires_at' => now()->addYear()->format('Y-m-d'),
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ItemLot::where('item_id', $f['item']->id)->sole()->code)->toBe('L-777');
});

it('rechaza dos lotes con el mismo número en el mismo artículo', function () {
    $f = lotHttpFixture();
    createLot($f, 'L-001');

    $this->post(route('item-lots.store', $f['item']->id), ['code' => 'L-001', 'status' => 'active'])
        ->assertSessionHasErrors('code');

    expect(ItemLot::where('item_id', $f['item']->id)->count())->toBe(1);
});

it('un movimiento de un artículo con lotes los exige, traducido a error de formulario', function () {
    $f = lotHttpFixture();

    $this->post(route('inventory-movements.store'), movementPayload($f, 'goods_receipt'))
        ->assertSessionHasErrors('lines');
});

it('rechaza eliminar un lote con movimientos', function () {
    $f = lotHttpFixture();
    $lot = createLot($f, 'L-001');

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $lot->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $this->delete(route('item-lots.destroy', [$f['item']->id, $lot->id]))
        ->assertSessionHasErrors('lot');

    expect(ItemLot::find($lot->id))->not->toBeNull();
});

it('elimina un lote sin movimientos', function () {
    $f = lotHttpFixture();
    $lot = createLot($f, 'L-001');

    $this->delete(route('item-lots.destroy', [$f['item']->id, $lot->id]))->assertSessionHasNoErrors();

    expect(ItemLot::find($lot->id))->toBeNull()
        ->and(ItemLotStock::count())->toBe(0);
});

it('el número de lote no se puede cambiar al editar', function () {
    $f = lotHttpFixture();
    $lot = createLot($f, 'L-001');

    $this->put(route('item-lots.update', [$f['item']->id, $lot->id]), [
        'code' => 'L-CAMBIADO',
        'status' => 'blocked',
    ])->assertSessionHasNoErrors();

    expect($lot->fresh()->code)->toBe('L-001')
        ->and($lot->fresh()->status)->toBe('blocked');
});

it('no se puede tocar un lote de un artículo de otra compañía', function () {
    $f = lotHttpFixture();

    $otraCompania = App\Domains\Core\Models\Company::factory()->create();
    $itemAjeno = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => $otraCompania->id,
        'uom_id' => $f['item']->uom_id,
    ]);

    $this->get(route('item-lots.index', $itemAjeno->id))->assertNotFound();
});

// ── Opciones para el formulario ──────────────────────────────────────────

it('el endpoint de opciones devuelve la sugerencia FEFO en una salida', function () {
    $f = lotHttpFixture();

    $lejano = createLot($f, 'L-LEJANO', now()->addMonths(6)->format('Y-m-d'));
    $cercano = createLot($f, 'L-CERCANO', now()->addMonths(1)->format('Y-m-d'));

    foreach ([$lejano, $cercano] as $lot) {
        $payload = movementPayload($f, 'goods_receipt');
        $payload['lines'][0]['item_lot_id'] = $lot->id;
        $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();
    }

    $res = $this->getJson(route('item-lots.options', $f['item']->id).'?'.http_build_query([
        'operation' => 'goods_issue',
        'warehouse_id' => $f['warehouse']->id,
    ]));

    $res->assertOk()
        ->assertJsonPath('fefo', true)
        ->assertJsonPath('lots.0.code', 'L-CERCANO')
        ->assertJsonPath('lots.1.code', 'L-LEJANO');
});

it('el endpoint de opciones no ofrece lotes vencidos', function () {
    $f = lotHttpFixture();

    createLot($f, 'L-VENCIDO', now()->subDay()->format('Y-m-d'));
    createLot($f, 'L-VIGENTE', now()->addMonth()->format('Y-m-d'));

    $res = $this->getJson(route('item-lots.options', $f['item']->id).'?operation=goods_receipt');

    $res->assertOk()->assertJsonCount(1, 'lots')->assertJsonPath('lots.0.code', 'L-VIGENTE');
});

it('el endpoint de opciones avisa cuando el artículo no maneja lotes', function () {
    $f = lotHttpFixture();
    $f['item']->update(['tracks_lots' => false]);

    $this->getJson(route('item-lots.options', $f['item']->id))
        ->assertOk()
        ->assertJsonPath('tracks_lots', false)
        ->assertJsonCount(0, 'lots');
});

// ── Trazabilidad ─────────────────────────────────────────────────────────

it('la trazabilidad muestra por dónde pasó el lote y dónde está hoy', function () {
    $f = lotHttpFixture();
    $lot = createLot($f, 'L-001', now()->addMonths(3)->format('Y-m-d'));

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $lot->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $salida = movementPayload($f, 'goods_issue');
    $salida['lines'][0]['item_lot_id'] = $lot->id;
    $salida['lines'][0]['quantity'] = 4;
    $this->post(route('inventory-movements.store'), $salida)->assertSessionHasNoErrors();

    $this->get(route('item-lots.trace', [$f['item']->id, $lot->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Items/LotTrace')
            ->has('movements', 2)
            ->has('balances', 1)
            ->where('balances.0.on_hand', fn ($value) => (float) $value === 6.0)
        );
});

// ── Próximos a vencer ────────────────────────────────────────────────────

it('el reporte de vencimientos solo lista lotes con saldo', function () {
    $f = lotHttpFixture();

    $conSaldo = createLot($f, 'L-CON', now()->addDays(10)->format('Y-m-d'));
    createLot($f, 'L-SIN', now()->addDays(10)->format('Y-m-d'));

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $conSaldo->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    $this->get(route('lot-expiry.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Lots/Expiry')
            ->has('rows', 1)
            ->where('rows.0.code', 'L-CON')
            ->where('rows.0.bucket', 'd15')
        );
});

it('el horizonte del reporte de vencimientos es un parámetro', function () {
    $f = lotHttpFixture();

    $lot = createLot($f, 'L-LEJANO', now()->addDays(200)->format('Y-m-d'));

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $lot->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    // Con el horizonte por defecto (90 días) no aparece...
    $this->get(route('lot-expiry.index'))
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    // ...y con uno de 365 sí.
    $this->get(route('lot-expiry.index', ['days' => 365]))
        ->assertInertia(fn ($page) => $page->has('rows', 1));
});

it('el reporte de vencimientos clasifica los ya vencidos en su propio grupo', function () {
    $f = lotHttpFixture();

    $lot = createLot($f, 'L-VIEJO', now()->addDay()->format('Y-m-d'));

    $payload = movementPayload($f, 'goods_receipt');
    $payload['lines'][0]['item_lot_id'] = $lot->id;
    $this->post(route('inventory-movements.store'), $payload)->assertSessionHasNoErrors();

    // Se vence después de haber recibido la mercancía.
    $lot->update(['expires_at' => now()->subDays(5)->format('Y-m-d')]);

    $this->get(route('lot-expiry.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.bucket', 'expired')
            ->where('summary.expired', 1)
        );
});
