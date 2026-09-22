<?php

use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Domains\Inventory\Services\PriceResolver;

/**
 * Compañía con dos listas: una predeterminada de mostrador y una de mayoreo,
 * ambas en moneda local, y un artículo con precio en las dos.
 */
function priceFixture(): array
{
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-1']);

    $retail = PriceList::factory()->default()->create([
        'company_id' => $company->id, 'code' => 'PUB',
        'currency_id' => $company->local_currency_id,
    ]);

    $wholesale = PriceList::factory()->create([
        'company_id' => $company->id, 'code' => 'MAY',
        'currency_id' => $company->local_currency_id,
    ]);

    PriceListItem::factory()->create(['price_list_id' => $retail->id, 'item_id' => $item->id, 'unit_price' => 1000]);
    PriceListItem::factory()->create(['price_list_id' => $wholesale->id, 'item_id' => $item->id, 'unit_price' => 700]);

    return compact('company', 'item', 'retail', 'wholesale');
}

function priceCustomer(array $f, ?int $priceListId = null): BusinessPartner
{
    return BusinessPartner::factory()->create([
        'company_id' => $f['company']->id,
        'price_list_id' => $priceListId,
    ]);
}

function resolvePrice(array $f, ?BusinessPartner $customer, ?string $date = null, ?int $currencyId = null)
{
    return app(PriceResolver::class)->resolve(
        $f['company'], $f['item']->id, $customer, $date ?? now()->format('Y-m-d'), $currencyId
    );
}

// --- Precedencia ---

it('un cliente sin lista propia usa la predeterminada', function () {
    $f = priceFixture();

    $result = resolvePrice($f, priceCustomer($f));

    expect($result->hasPrice())->toBeTrue()
        ->and((float) $result->unitPrice)->toBe(1000.0)
        ->and($result->priceList->code)->toBe('PUB');
});

it('LA PRUEBA DE LA PRECEDENCIA: la lista del cliente le gana a la predeterminada', function () {
    $f = priceFixture();

    $result = resolvePrice($f, priceCustomer($f, $f['wholesale']->id));

    expect((float) $result->unitPrice)->toBe(700.0)
        ->and($result->priceList->code)->toBe('MAY');
});

it('sin cliente usa la predeterminada: un tiquete a consumidor final también lleva precio', function () {
    $f = priceFixture();

    expect((float) resolvePrice($f, null)->unitPrice)->toBe(1000.0);
});

it('sin lista predeterminada no hay precio, y lo dice', function () {
    $f = priceFixture();

    $f['retail']->update(['is_default' => false]);

    $result = resolvePrice($f, priceCustomer($f));

    expect($result->hasPrice())->toBeFalse()
        ->and($result->reason)->toBe('no_list')
        // El mensaje nombra los tres escalones, para que se sepa dónde
        // configurar en vez de tener que adivinarlo.
        ->and($result->message())->toContain('no tiene una propia')
        ->and($result->message())->toContain('su categoría tampoco')
        ->and($result->message())->toContain('lista predeterminada');
});

it('una lista inactiva no puede ser la predeterminada efectiva', function () {
    $f = priceFixture();

    $f['retail']->update(['status' => 'inactive']);

    expect(resolvePrice($f, priceCustomer($f))->reason)->toBe('no_list');
});

// --- La decisión menos obvia ---

it('LA PRUEBA CENTRAL: si falta el artículo en la lista del cliente NO cae a la predeterminada', function () {
    $f = priceFixture();

    // Al mayorista se le olvidó ponerle precio a este artículo. Caer a la
    // lista de mostrador le cobraría MÁS que lo pactado, en silencio, y el
    // error aparecería cuando el cliente reclame. Sin precio, la línea llega
    // vacía y alguien la digita: es lo que pasaba antes de las listas.
    PriceListItem::where('price_list_id', $f['wholesale']->id)->delete();

    $result = resolvePrice($f, priceCustomer($f, $f['wholesale']->id));

    expect($result->hasPrice())->toBeFalse()
        ->and($result->reason)->toBe('item_not_in_list')
        ->and($result->message())->toContain('MAY');
});

it('una lista propia vencida tampoco cae a la predeterminada', function () {
    $f = priceFixture();

    $f['wholesale']->update(['valid_to' => '2020-12-31']);

    // Mismo razonamiento: sustituirla en silencio sería cobrar de más.
    $result = resolvePrice($f, priceCustomer($f, $f['wholesale']->id));

    expect($result->hasPrice())->toBeFalse()
        ->and($result->reason)->toBe('list_not_valid');
});

// --- Vigencia ---

it('respeta la vigencia de la lista', function () {
    $f = priceFixture();

    $f['retail']->update(['valid_from' => '2026-01-01', 'valid_to' => '2026-12-31']);

    $customer = priceCustomer($f);

    expect(resolvePrice($f, $customer, '2026-06-15')->hasPrice())->toBeTrue()
        ->and(resolvePrice($f, $customer, '2025-12-31')->hasPrice())->toBeFalse()
        ->and(resolvePrice($f, $customer, '2027-01-01')->hasPrice())->toBeFalse()
        // Los bordes son inclusivos: una lista vigente "hasta el 31" aplica
        // el 31.
        ->and(resolvePrice($f, $customer, '2026-01-01')->hasPrice())->toBeTrue()
        ->and(resolvePrice($f, $customer, '2026-12-31')->hasPrice())->toBeTrue();
});

it('sin fechas la lista no vence nunca', function () {
    $f = priceFixture();

    expect(resolvePrice($f, priceCustomer($f), '2099-01-01')->hasPrice())->toBeTrue();
});

// --- Moneda ---

it('LA OTRA PRUEBA CENTRAL: no convierte de moneda', function () {
    $f = priceFixture();

    $dolar = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dólar', 'symbol' => '$', 'decimal_places' => 2]);

    // La lista está en colones y la factura en dólares. Convertir al tipo de
    // cambio del día haría que el precio cambiara solo, todos los días, sin
    // que nadie lo decidiera.
    $result = resolvePrice($f, priceCustomer($f), null, $dolar->id);

    expect($result->hasPrice())->toBeFalse()
        ->and($result->reason)->toBe('currency_mismatch')
        ->and($result->message())->toContain('no se convierte solo');
});

it('una lista en la moneda del documento sí aplica', function () {
    $f = priceFixture();

    $dolar = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dólar', 'symbol' => '$', 'decimal_places' => 2]);

    $listaUsd = PriceList::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'USD-PUB', 'currency_id' => $dolar->id,
    ]);
    PriceListItem::factory()->create([
        'price_list_id' => $listaUsd->id, 'item_id' => $f['item']->id, 'unit_price' => 2.5,
    ]);

    $result = resolvePrice($f, priceCustomer($f, $listaUsd->id), null, $dolar->id);

    expect((float) $result->unitPrice)->toBe(2.5);
});

// --- Aislamiento ---

it('no resuelve una lista de otra compañía', function () {
    $f = priceFixture();

    $otra = Company::factory()->create();
    $ajena = PriceList::factory()->default()->create([
        'company_id' => $otra->id, 'currency_id' => $otra->local_currency_id,
    ]);

    // El cliente apunta a una lista que no es de su compañía: se ignora y
    // cae a la predeterminada propia, no a la ajena.
    $customer = priceCustomer($f);
    $customer->forceFill(['price_list_id' => $ajena->id])->save();

    expect(resolvePrice($f, $customer)->priceList->code)->toBe('PUB');
});

// --- El mapa que usa la factura ---

it('el mapa trae todos los precios de la lista de una sola vez', function () {
    $f = priceFixture();

    $otro = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'ART-2']);
    PriceListItem::factory()->create([
        'price_list_id' => $f['retail']->id, 'item_id' => $otro->id, 'unit_price' => 250,
    ]);

    $map = app(PriceResolver::class)->priceMap($f['company'], priceCustomer($f), now()->format('Y-m-d'));

    expect($map['reason'])->toBe('found')
        ->and($map['list']->code)->toBe('PUB')
        ->and($map['prices'])->toHaveCount(2)
        ->and((float) $map['prices'][$f['item']->id])->toBe(1000.0)
        ->and((float) $map['prices'][$otro->id])->toBe(250.0);
});

it('el mapa devuelve vacío con el mismo motivo que el precio individual', function () {
    $f = priceFixture();

    $dolar = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dólar', 'symbol' => '$', 'decimal_places' => 2]);

    $map = app(PriceResolver::class)->priceMap(
        $f['company'], priceCustomer($f), now()->format('Y-m-d'), $dolar->id
    );

    expect($map['prices'])->toBeEmpty()->and($map['reason'])->toBe('currency_mismatch');
});

// --- Precio contra costo ---

it('avisa qué líneas venden por debajo del costo promedio', function () {
    $f = priceFixture();

    // Cuesta 1.200 y la lista lo vende a 1.000.
    $f['item']->update(['avg_cost_local' => 1200]);

    $below = app(PriceResolver::class)->linesBelowCost($f['retail']);

    expect($below)->toHaveCount(1)
        ->and($below[0]['item_code'])->toBe('ART-1')
        ->and((float) $below[0]['difference'])->toBe(-200.0);
});

it('un costo en cero no cuenta como vender bajo costo', function () {
    $f = priceFixture();

    // Cero es "nunca entró mercancía", no "es gratis". Sin este filtro todo
    // el catálogo nuevo saldría marcado.
    $f['item']->update(['avg_cost_local' => 0]);

    expect(app(PriceResolver::class)->linesBelowCost($f['retail']))->toHaveCount(0);
});

it('vender exactamente al costo no es vender bajo costo', function () {
    $f = priceFixture();

    $f['item']->update(['avg_cost_local' => 1000]);

    expect(app(PriceResolver::class)->linesBelowCost($f['retail']))->toHaveCount(0);
});

// --- La frontera con el costeo ---

it('LA PRUEBA DE LA FRONTERA: poner precio no toca el costo promedio ni genera asiento', function () {
    $f = priceFixture();

    $f['item']->update(['avg_cost_local' => 800]);
    $asientosAntes = App\Domains\Accounting\Models\JournalEntry::count();

    PriceListItem::updateOrCreate(
        ['price_list_id' => $f['retail']->id, 'item_id' => $f['item']->id],
        ['unit_price' => 99999],
    );

    // El precio es una decisión comercial: no es un hecho económico hasta
    // que se factura, y ahí el asiento lo hace la factura.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(800.0)
        ->and(App\Domains\Accounting\Models\JournalEntry::count())->toBe($asientosAntes);
});

// --- El escalón del medio: heredar de la categoría ---

function priceCategory(array $f, ?int $priceListId = null): BpCategory
{
    return BpCategory::factory()->create([
        'company_id' => $f['company']->id,
        'price_list_id' => $priceListId,
    ]);
}

it('LA PRUEBA DE LA HERENCIA: un cliente sin lista propia toma la de su categoría', function () {
    $f = priceFixture();

    // Se configura una vez en la categoría en vez de en 400 clientes.
    $categoria = priceCategory($f, $f['wholesale']->id);

    $customer = priceCustomer($f);
    $customer->update(['category_id' => $categoria->id]);

    $result = resolvePrice($f, $customer->fresh());

    expect((float) $result->unitPrice)->toBe(700.0)
        ->and($result->priceList->code)->toBe('MAY');
});

it('la lista propia del cliente le gana a la de su categoría', function () {
    $f = priceFixture();

    // La categoría dice mayoreo, pero a este cliente se le pactó mostrador.
    $categoria = priceCategory($f, $f['wholesale']->id);

    $customer = priceCustomer($f, $f['retail']->id);
    $customer->update(['category_id' => $categoria->id]);

    expect(resolvePrice($f, $customer->fresh())->priceList->code)->toBe('PUB');
});

it('una categoría sin lista no cambia nada: se cae a la predeterminada', function () {
    $f = priceFixture();

    // Es el caso de todas las categorías que ya existían antes de esta
    // función: siguen agrupando reportes y nada más.
    $customer = priceCustomer($f);
    $customer->update(['category_id' => priceCategory($f)->id]);

    expect(resolvePrice($f, $customer->fresh())->priceList->code)->toBe('PUB');
});

it('un cliente sin categoría sigue cayendo a la predeterminada', function () {
    $f = priceFixture();

    expect(resolvePrice($f, priceCustomer($f))->priceList->code)->toBe('PUB');
});

it('la lista heredada tampoco cae a la general cuando falta el artículo', function () {
    $f = priceFixture();

    $categoria = priceCategory($f, $f['wholesale']->id);
    $customer = priceCustomer($f);
    $customer->update(['category_id' => $categoria->id]);

    // Mismo razonamiento que con la lista propia: caer a mostrador le
    // cobraría de más a toda la categoría, en silencio.
    PriceListItem::where('price_list_id', $f['wholesale']->id)->delete();

    $result = resolvePrice($f, $customer->fresh());

    expect($result->hasPrice())->toBeFalse()
        ->and($result->reason)->toBe('item_not_in_list')
        ->and($result->priceList->code)->toBe('MAY');
});

it('una lista heredada vencida tampoco cae a la predeterminada', function () {
    $f = priceFixture();

    $f['wholesale']->update(['valid_to' => '2020-12-31']);

    $customer = priceCustomer($f);
    $customer->update(['category_id' => priceCategory($f, $f['wholesale']->id)->id]);

    expect(resolvePrice($f, $customer->fresh())->reason)->toBe('list_not_valid');
});

it('una lista heredada de otra compañía se ignora y se sigue bajando', function () {
    $f = priceFixture();

    $otra = Company::factory()->create();
    $ajena = PriceList::factory()->create([
        'company_id' => $otra->id, 'currency_id' => $otra->local_currency_id,
    ]);

    $categoria = priceCategory($f);
    $categoria->forceFill(['price_list_id' => $ajena->id])->save();

    $customer = priceCustomer($f);
    $customer->update(['category_id' => $categoria->id]);

    expect(resolvePrice($f, $customer->fresh())->priceList->code)->toBe('PUB');
});

it('el mapa de la factura respeta la herencia igual que el precio individual', function () {
    $f = priceFixture();

    $customer = priceCustomer($f);
    $customer->update(['category_id' => priceCategory($f, $f['wholesale']->id)->id]);

    $map = app(PriceResolver::class)->priceMap($f['company'], $customer->fresh(), now()->format('Y-m-d'));

    expect($map['list']->code)->toBe('MAY')
        ->and((float) $map['prices'][$f['item']->id])->toBe(700.0);
});
