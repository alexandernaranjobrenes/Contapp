<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Models\PricingPackage;
use App\Domains\Pricing\Models\PricingSetting;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Planes y precios desde el backoffice
|--------------------------------------------------------------------------
|
| Los ajustes, las funciones con su precio y los paquetes (CLAUDE.md secc.
| 42). Cada cambio queda en la bitácora del backoffice.
|
*/

function featurePayload(array $overrides = []): array
{
    return array_merge([
        'key' => '',
        'name' => 'Planillas',
        'description' => 'CCSS e impuesto al salario.',
        'icon' => 'users',
        'group' => 'Operación',
        'kind' => 'toggle',
        'monthly_crc' => '12000',
        'annual_crc' => null,
        'monthly_usd' => '24',
        'annual_usd' => null,
        'is_required' => 0,
        'is_active' => 1,
        'module_key' => 'payroll',
        'sort_order' => 10,
        'requires' => [],
    ], $overrides);
}

it('guarda los ajustes y los anota en la bitácora', function () {
    loginAsPropietario();

    $this->put(route('backoffice.pricing.settings.update'), [
        'default_currency' => 'USD',
        'tax_rate' => '13',
        'prices_include_tax' => 1,
        'notification_email' => 'servicios@ncodedigital.com',
    ])->assertSessionHasNoErrors();

    expect(PricingSetting::current())
        ->default_currency->toBe('USD')
        ->prices_include_tax->toBeTrue()
        ->and(AuditLog::where('action', 'pricing_settings_updated')->exists())->toBeTrue();
});

it('carga las funciones sugeridas, sin precio e inactivas, una sola vez', function () {
    loginAsPropietario();

    $this->post(route('backoffice.pricing.defaults'))->assertSessionHas('success');
    $count = PricingFeature::count();

    expect($count)->toBeGreaterThan(10)
        ->and(PricingFeature::where('is_active', true)->count())->toBe(0)
        ->and(PricingFeature::where('key', 'contabilidad')->value('is_required'))->toBeTrue()
        ->and(PricingFeature::where('key', 'factura_electronica')->first()->requirements->pluck('key')->all())->toBe(['facturacion']);

    $this->post(route('backoffice.pricing.defaults'));
    expect(PricingFeature::count())->toBe($count);
});

it('crea una función con la clave armada del nombre', function () {
    loginAsPropietario();

    $this->post(route('backoffice.pricing.features.store'), featurePayload())->assertSessionHasNoErrors();

    $feature = PricingFeature::sole();

    expect($feature->key)->toBe('planillas')
        ->and($feature->priceCents('annual', 'CRC'))->toBe(144_000_00)
        ->and(AuditLog::where('action', 'pricing_feature_created')->exists())->toBeTrue();
});

it('una función por cantidad pide su unidad, y el máximo no baja de las incluidas', function () {
    loginAsPropietario();

    $this->post(route('backoffice.pricing.features.store'), featurePayload(['kind' => 'quantity']))
        ->assertSessionHasErrors(['unit_singular', 'unit_plural']);

    $this->post(route('backoffice.pricing.features.store'), featurePayload([
        'kind' => 'quantity', 'unit_singular' => 'usuario', 'unit_plural' => 'usuarios', 'included_quantity' => 5, 'max_quantity' => 2,
    ]))->assertSessionHasErrors('max_quantity');
});

it('no deja armar un círculo de funciones que se necesitan', function () {
    loginAsPropietario();
    $a = pricingFeature('a');
    $b = pricingFeature('b');
    $b->requirements()->attach($a);

    $this->put(route('backoffice.pricing.features.update', $a), featurePayload(['key' => 'a', 'name' => 'A', 'requires' => [$b->id]]))
        ->assertSessionHasErrors('requires');

    $this->put(route('backoffice.pricing.features.update', $a), featurePayload(['key' => 'a', 'name' => 'A', 'requires' => [$a->id]]))
        ->assertSessionHasErrors('requires');
});

it('un paquete se guarda con lo obligatorio y lo que sus funciones necesitan', function () {
    loginAsPropietario();
    ['contabilidad' => $contabilidad, 'inventario' => $inventario, 'facturacion' => $facturacion, 'companias' => $companias] = pricingCatalogFixture();

    $this->post(route('backoffice.pricing.packages.store'), [
        'name' => 'Comercio',
        'billing' => 'annual',
        'discount_percent' => '5',
        'is_active' => 1,
        'is_highlighted' => 1,
        'features' => [$facturacion->id => 1],
    ])->assertSessionHasNoErrors();

    $package = PricingPackage::where('slug', 'comercio')->sole();

    expect($package->features->pluck('id')->sort()->values()->all())
        ->toBe(collect([$contabilidad->id, $inventario->id, $facturacion->id, $companias->id])->sort()->values()->all())
        ->and($package->billing)->toBe('annual');
});

it('eliminar una función la quita de los paquetes', function () {
    loginAsPropietario();
    ['inventario' => $inventario, 'pyme' => $pyme] = pricingCatalogFixture();

    $this->delete(route('backoffice.pricing.features.destroy', $inventario))->assertSessionHas('success');

    expect($pyme->features()->where('pricing_features.id', $inventario->id)->exists())->toBeFalse();
});

it('Planes y precios es solo del Propietario', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('backoffice.pricing.index'))->assertRedirect(route('backoffice.login'));
    $this->get(route('backoffice.license-requests.index'))->assertRedirect(route('backoffice.login'));
});

it('el menú del backoffice cuenta las solicitudes sin atender', function () {
    pricingCatalogFixture();
    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
    loginAsPropietario();

    $this->get(route('backoffice.pricing.index'))
        ->assertInertia(fn ($page) => $page->component('Backoffice/Pricing/Index')->where('backofficeBadges.license_requests', 1));
});
