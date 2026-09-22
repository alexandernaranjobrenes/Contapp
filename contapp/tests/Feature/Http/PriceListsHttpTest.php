<?php

use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;

function priceListHttpFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    return [
        'company' => $company,
        'item' => Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-1']),
    ];
}

function makePriceList(array $f, array $attributes = []): PriceList
{
    return PriceList::factory()->create(array_merge([
        'company_id' => $f['company']->id,
        'currency_id' => $f['company']->local_currency_id,
    ], $attributes));
}

// --- La lista ---

it('crea una lista de precios', function () {
    $f = priceListHttpFixture();

    $this->post(route('price-lists.store'), [
        'code' => 'PUB', 'name' => 'Público', 'currency_id' => $f['company']->local_currency_id,
        'prices_include_tax' => true, 'is_default' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $list = PriceList::where('company_id', $f['company']->id)->sole();

    expect($list->code)->toBe('PUB')
        ->and($list->prices_include_tax)->toBeTrue()
        ->and($list->is_default)->toBeTrue();
});

it('rechaza el código duplicado en la misma compañía', function () {
    $f = priceListHttpFixture();
    makePriceList($f, ['code' => 'PUB']);

    $this->post(route('price-lists.store'), [
        'code' => 'PUB', 'name' => 'Repetida', 'currency_id' => $f['company']->local_currency_id,
        'status' => 'active',
    ])->assertSessionHasErrors('code');

    expect(PriceList::where('company_id', $f['company']->id)->count())->toBe(1);
});

it('LA PRUEBA DE LA PREDETERMINADA: marcar una desmarca la anterior', function () {
    $f = priceListHttpFixture();
    $primera = makePriceList($f, ['code' => 'PUB', 'is_default' => true]);

    // Dos predeterminadas harían que el precio de un cliente sin lista propia
    // dependiera del orden de la consulta.
    $this->post(route('price-lists.store'), [
        'code' => 'MAY', 'name' => 'Mayoreo', 'currency_id' => $f['company']->local_currency_id,
        'is_default' => true, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($primera->fresh()->is_default)->toBeFalse()
        ->and(PriceList::where('company_id', $f['company']->id)->where('is_default', true)->count())->toBe(1);
});

it('rechaza una vigencia que termina antes de empezar', function () {
    $f = priceListHttpFixture();

    $this->post(route('price-lists.store'), [
        'code' => 'X', 'name' => 'Imposible', 'currency_id' => $f['company']->local_currency_id,
        'valid_from' => '2026-12-01', 'valid_to' => '2026-01-01', 'status' => 'active',
    ])->assertSessionHasErrors('valid_to');

    expect(PriceList::where('company_id', $f['company']->id)->count())->toBe(0);
});

it('no deja eliminar una lista con clientes asignados', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f, ['code' => 'MAY']);

    BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'price_list_id' => $list->id,
    ]);

    // Quedarían apuntando a nada y sus facturas sin precio, sin aviso.
    $this->delete(route('price-lists.destroy', $list->id))->assertSessionHasErrors('price_list');

    expect(PriceList::find($list->id))->not->toBeNull();
});

it('elimina una lista que nadie usa', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f);

    $this->delete(route('price-lists.destroy', $list->id))->assertSessionHasNoErrors();

    expect(PriceList::find($list->id))->toBeNull();
});

it('el listado cuenta artículos y clientes de cada lista', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f, ['code' => 'PUB']);

    PriceListItem::factory()->create(['price_list_id' => $list->id, 'item_id' => $f['item']->id]);
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'price_list_id' => $list->id]);

    $this->get(route('price-lists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/PriceLists/Index')
            ->where('priceLists.0.lines_count', 1)
            ->where('priceLists.0.customers_count', 1)
        );
});

it('solo lista las listas de la compañía activa', function () {
    $f = priceListHttpFixture();
    makePriceList($f, ['code' => 'PROPIA']);

    $otra = App\Domains\Core\Models\Company::factory()->create();
    PriceList::factory()->create(['company_id' => $otra->id, 'currency_id' => $otra->local_currency_id]);

    $this->get(route('price-lists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('priceLists', 1)->where('priceLists.0.code', 'PROPIA'));
});

// --- Los precios dentro de la lista ---

it('guarda los precios de la lista', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f);

    $this->put(route('price-lists.prices.update', $list->id), [
        'prices' => [['item_id' => $f['item']->id, 'unit_price' => 1500]],
    ])->assertSessionHasNoErrors();

    expect((float) PriceListItem::where('price_list_id', $list->id)->sole()->unit_price)->toBe(1500.0);
});

it('LA PRUEBA DEL VACÍO: dejar el precio vacío QUITA el artículo, poner cero lo regala', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f);

    PriceListItem::factory()->create([
        'price_list_id' => $list->id, 'item_id' => $f['item']->id, 'unit_price' => 1500,
    ]);

    // Cero es un precio: el artículo sigue en la lista, a 0.
    $this->put(route('price-lists.prices.update', $list->id), [
        'prices' => [['item_id' => $f['item']->id, 'unit_price' => 0]],
    ]);

    expect(PriceListItem::where('price_list_id', $list->id)->count())->toBe(1);

    // Vacío no: lo saca de la lista.
    $this->put(route('price-lists.prices.update', $list->id), [
        'prices' => [['item_id' => $f['item']->id, 'unit_price' => null]],
    ]);

    expect(PriceListItem::where('price_list_id', $list->id)->count())->toBe(0);
});

it('rechaza un artículo de otra compañía', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f);

    $otra = App\Domains\Core\Models\Company::factory()->create();
    $ajeno = Item::factory()->create(['company_id' => $otra->id]);

    $this->put(route('price-lists.prices.update', $list->id), [
        'prices' => [['item_id' => $ajeno->id, 'unit_price' => 100]],
    ])->assertSessionHasErrors('prices.0.item_id');
});

it('la pantalla de precios avisa qué artículos van por debajo del costo', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f);

    $f['item']->update(['avg_cost_local' => 2000, 'is_sales_item' => true]);
    PriceListItem::factory()->create([
        'price_list_id' => $list->id, 'item_id' => $f['item']->id, 'unit_price' => 1500,
    ]);

    $this->get(route('price-lists.prices', $list->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/PriceLists/Prices')
            ->has('belowCost', 1)
            ->where('belowCost.0.item_code', 'ART-1')
        );
});

// --- El endpoint que usa la factura ---

it('LA PRUEBA DEL CIERRE: la factura pide los precios del cliente y los recibe', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f, ['code' => 'PUB', 'is_default' => true]);

    PriceListItem::factory()->create([
        'price_list_id' => $list->id, 'item_id' => $f['item']->id, 'unit_price' => 1500,
    ]);

    $customer = BusinessPartner::factory()->create(['company_id' => $f['company']->id]);

    $response = $this->getJson(route('price-lists.for-customer', [
        'business_partner_id' => $customer->id,
        'date' => now()->format('Y-m-d'),
        'currency_id' => $f['company']->local_currency_id,
    ]));

    $response->assertOk()
        ->assertJsonPath('reason', 'found')
        ->assertJsonPath('list.code', 'PUB');

    expect((float) $response->json("prices.{$f['item']->id}"))->toBe(1500.0);
});

it('el endpoint devuelve el motivo cuando no hay precios, en vez de un vacío mudo', function () {
    $f = priceListHttpFixture();

    $customer = BusinessPartner::factory()->create(['company_id' => $f['company']->id]);

    $this->getJson(route('price-lists.for-customer', ['business_partner_id' => $customer->id]))
        ->assertOk()
        ->assertJsonPath('reason', 'no_list')
        ->assertJsonPath('prices', []);
});

it('el endpoint no convierte de moneda', function () {
    $f = priceListHttpFixture();
    $list = makePriceList($f, ['is_default' => true]);

    PriceListItem::factory()->create(['price_list_id' => $list->id, 'item_id' => $f['item']->id]);

    $dolar = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dólar', 'symbol' => '$', 'decimal_places' => 2]);

    $this->getJson(route('price-lists.for-customer', ['currency_id' => $dolar->id]))
        ->assertOk()
        ->assertJsonPath('reason', 'currency_mismatch')
        ->assertJsonPath('prices', []);
});

it('rechaza un cliente de otra compañía', function () {
    priceListHttpFixture();

    $otra = App\Domains\Core\Models\Company::factory()->create();
    $ajeno = BusinessPartner::factory()->create(['company_id' => $otra->id]);

    // La validación lo rechaza. La ruta es web, así que el fallo sale como
    // redirect y no como 422; lo que importa es que no devuelve precios de
    // una compañía ajena.
    $response = $this->getJson(route('price-lists.for-customer', ['business_partner_id' => $ajeno->id]));

    expect($response->status())->not->toBe(200);
});
// --- Asignar la lista al cliente ---

it('LA PRUEBA QUE FALTABA: la ficha del cliente guarda su lista de precios', function () {
    $f = priceListHttpFixture();
    $mayoreo = makePriceList($f, ['code' => 'MAY']);

    $cuenta = App\Domains\Accounting\Models\ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'accepts_posting' => true, 'is_active' => true,
    ]);

    $this->post(route('business-partners.store'), [
        'code' => 'C-001', 'name' => 'Distribuidora del Sur', 'type' => 'client',
        'gl_account_id' => $cuenta->id, 'currency_id' => $f['company']->local_currency_id,
        'price_list_id' => $mayoreo->id,
    ])->assertSessionHasNoErrors();

    expect(BusinessPartner::where('company_id', $f['company']->id)->sole()->price_list_id)
        ->toBe($mayoreo->id);
});

it('un cliente sin lista asignada queda en null, que es "usa la predeterminada"', function () {
    $f = priceListHttpFixture();

    $cuenta = App\Domains\Accounting\Models\ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'accepts_posting' => true, 'is_active' => true,
    ]);

    $this->post(route('business-partners.store'), [
        'code' => 'C-002', 'name' => 'Cliente de mostrador', 'type' => 'client',
        'gl_account_id' => $cuenta->id, 'currency_id' => $f['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    expect(BusinessPartner::where('company_id', $f['company']->id)->sole()->price_list_id)->toBeNull();
});

it('se le puede cambiar la lista a un cliente existente', function () {
    $f = priceListHttpFixture();
    $mayoreo = makePriceList($f, ['code' => 'MAY']);

    $cuenta = App\Domains\Accounting\Models\ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'accepts_posting' => true, 'is_active' => true,
    ]);

    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'client', 'gl_account_id' => $cuenta->id,
    ]);

    $this->put(route('business-partners.update', $cliente->id), [
        'code' => $cliente->code, 'name' => $cliente->name, 'type' => 'client',
        'gl_account_id' => $cuenta->id, 'currency_id' => $cliente->currency_id,
        'price_list_id' => $mayoreo->id,
    ])->assertSessionHasNoErrors();

    expect($cliente->fresh()->price_list_id)->toBe($mayoreo->id);
});

it('rechaza asignar una lista de otra compañía', function () {
    $f = priceListHttpFixture();

    $otra = App\Domains\Core\Models\Company::factory()->create();
    $ajena = PriceList::factory()->create([
        'company_id' => $otra->id, 'currency_id' => $otra->local_currency_id,
    ]);

    $cliente = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'type' => 'client',
    ]);

    $this->put(route('business-partners.update', $cliente->id), [
        'code' => $cliente->code, 'name' => $cliente->name, 'type' => 'client',
        'gl_account_id' => $cliente->gl_account_id, 'currency_id' => $cliente->currency_id,
        'price_list_id' => $ajena->id,
    ])->assertSessionHasErrors('price_list_id');
});

it('la ficha del cliente recibe las listas activas para poder ofrecerlas', function () {
    $f = priceListHttpFixture();
    makePriceList($f, ['code' => 'MAY']);
    makePriceList($f, ['code' => 'VIEJA', 'status' => 'inactive']);

    // Una lista inactiva no se ofrece: asignarla sería darle al cliente un
    // precio que nunca va a aplicar.
    $this->get(route('business-partners.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('priceLists', 1)
            ->where('priceLists.0.code', 'MAY')
        );
});
