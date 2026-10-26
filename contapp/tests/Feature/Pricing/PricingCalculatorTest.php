<?php

use App\Domains\Pricing\Models\PricingSetting;
use App\Domains\Pricing\Support\PricingCalculator;
use App\Domains\Pricing\Support\PricingCatalog;
use App\Domains\Pricing\Support\PricingMoney;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| La cotización de un plan
|--------------------------------------------------------------------------
|
| El mismo cálculo corre en el navegador (Utils/pricing.js); el que vale es
| este, que repite el servidor al recibir la solicitud (CLAUDE.md secc. 42).
|
*/

it('cotiza un paquete entero, con su descuento y el IVA aparte', function () {
    pricingCatalogFixture();

    $quote = PricingCalculator::quote(PricingCatalog::forSite(), 'pyme', ['contabilidad' => 1, 'inventario' => 1, 'facturacion' => 1, 'companias' => 3], 'monthly', 'CRC');

    // 20.000 + 15.000 + 10.000 + 2 compañías extra × 5.000 = 55.000
    expect($quote['subtotal'])->toBe(5_500_000)
        ->and($quote['intact'])->toBeTrue()
        ->and($quote['discount'])->toBe(550_000)
        ->and($quote['net'])->toBe(4_950_000)
        ->and($quote['tax'])->toBe(643_500)
        ->and($quote['total'])->toBe(5_593_500)
        ->and(collect($quote['lines'])->firstWhere('key', 'companias'))->toMatchArray(['quantity' => 3, 'billable' => 2, 'amount' => 1_000_000]);
});

it('quitar una función del paquete le quita el descuento; agregar no', function () {
    ['companias' => $companias] = pricingCatalogFixture();
    $catalog = PricingCatalog::forSite();

    $broken = PricingCalculator::quote($catalog, 'pyme', ['contabilidad' => 1, 'inventario' => 1, 'companias' => 3], 'monthly', 'CRC');
    expect($broken['intact'])->toBeFalse()->and($broken['discount'])->toBe(0);

    $more = PricingCalculator::quote($catalog, 'pyme', ['contabilidad' => 1, 'inventario' => 1, 'facturacion' => 1, 'companias' => 5], 'monthly', 'CRC');
    // El descuento es sobre lo del paquete (3 compañías), no sobre las 2 de más.
    expect($more['intact'])->toBeTrue()->and($more['discount'])->toBe(550_000);
});

it('suma sola lo obligatorio y lo que una función necesita', function () {
    pricingCatalogFixture();

    $quote = PricingCalculator::quote(PricingCatalog::forSite(), null, ['facturacion' => 1], 'monthly', 'CRC');

    expect(collect($quote['lines'])->pluck('key')->all())->toBe(['contabilidad', 'inventario', 'facturacion', 'companias'])
        ->and($quote['added'])->toBe(['inventario'])
        ->and($quote['quantities']['companias'])->toBe(1);
});

it('respeta el tope de una función por cantidad', function () {
    pricingCatalogFixture();

    $quote = PricingCalculator::quote(PricingCatalog::forSite(), null, ['companias' => 50], 'monthly', 'CRC');

    expect($quote['quantities']['companias'])->toBe(10);
});

it('el anual sin precio propio es doce veces el mensual, y el paquete puede fijar el ciclo', function () {
    ['pyme' => $pyme] = pricingCatalogFixture();
    $catalog = PricingCatalog::forSite();

    $annual = PricingCalculator::quote($catalog, null, [], 'annual', 'USD');
    // Contabilidad trae su anual (en colones; en dólares no): 12 × 40 = 480; 1 compañía incluida.
    expect($annual['cycle'])->toBe('annual')->and($annual['subtotal'])->toBe(48_000);

    $pyme->update(['billing' => 'monthly']);
    $forced = PricingCalculator::quote(PricingCatalog::forSite(), 'pyme', [], 'annual', 'CRC');
    expect($forced['cycle'])->toBe('monthly');
});

it('con los precios con IVA incluido, el total es el neto y el IVA se desglosa', function () {
    pricingCatalogFixture();
    PricingSetting::current()->update(['prices_include_tax' => true]);

    $quote = PricingCalculator::quote(PricingCatalog::forSite(), null, [], 'monthly', 'CRC');

    // Solo lo obligatorio: 20.000 con IVA incluido.
    expect($quote['total'])->toBe(2_000_000)
        ->and($quote['tax'])->toBe(2_000_000 - (int) round(2_000_000 / 1.13));
});

it('lo inactivo no existe para el sitio', function () {
    ['inventario' => $inventario] = pricingCatalogFixture();
    $inventario->update(['is_active' => false]);

    $catalog = PricingCatalog::forSite();

    expect(collect($catalog['features'])->pluck('key'))->not->toContain('inventario')
        ->and((array) $catalog['packages'][0]['features'])->not->toHaveKey('inventario');
});

it('escribe los montos como la aplicación', function () {
    expect(PricingMoney::format(2_500_000, 'CRC'))->toBe('₡25.000')
        ->and(PricingMoney::format(1_234_550, 'CRC'))->toBe('₡12.345,50')
        ->and(PricingMoney::format(4_900, 'USD'))->toBe('US$49')
        ->and(PricingMoney::format(-550_000, 'CRC'))->toBe('−₡5.500');
});
