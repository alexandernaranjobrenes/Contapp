<?php

use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Models\PricingPackage;
use App\Domains\Pricing\Models\PricingSetting;
use Illuminate\Support\Facades\Crypt;

/*
| Ayudas de las pruebas de planes y precios (CLAUDE.md secc. 42).
*/

if (! function_exists('pricingFeature')) {
    function pricingFeature(string $key, array $attributes = []): PricingFeature
    {
        return PricingFeature::create([
            'key' => $key,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'group' => 'Módulos',
            'icon' => 'package',
            'kind' => 'toggle',
            'monthly_crc' => 10000,
            'monthly_usd' => 20,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}

if (! function_exists('pricingCatalogFixture')) {
    /**
     * Un catálogo de prueba: Contabilidad obligatoria, Inventario, Facturación
     * (necesita Inventario), Compañías por cantidad (1 incluida) y el paquete
     * «Pyme» con 10 % de descuento.
     *
     * @return array{contabilidad: PricingFeature, inventario: PricingFeature, facturacion: PricingFeature, companias: PricingFeature, pyme: PricingPackage}
     */
    function pricingCatalogFixture(): array
    {
        PricingSetting::current()->update(['tax_rate' => 13, 'prices_include_tax' => false, 'default_currency' => 'CRC']);

        $contabilidad = pricingFeature('contabilidad', ['is_required' => true, 'monthly_crc' => 20000, 'annual_crc' => 200000, 'monthly_usd' => 40]);
        $inventario = pricingFeature('inventario', ['monthly_crc' => 15000, 'monthly_usd' => 30]);
        $facturacion = pricingFeature('facturacion', ['monthly_crc' => 10000, 'monthly_usd' => 20]);
        $facturacion->requirements()->attach($inventario);
        $companias = pricingFeature('companias', [
            'kind' => 'quantity', 'unit_singular' => 'compañía', 'unit_plural' => 'compañías',
            'included_quantity' => 1, 'max_quantity' => 10, 'monthly_crc' => 5000, 'monthly_usd' => 10, 'is_required' => true,
        ]);

        $pyme = PricingPackage::create(['name' => 'Pyme', 'slug' => 'pyme', 'billing' => 'both', 'discount_percent' => 10, 'is_active' => true]);
        $pyme->features()->attach([
            $contabilidad->id => ['quantity' => 1],
            $inventario->id => ['quantity' => 1],
            $facturacion->id => ['quantity' => 1],
            $companias->id => ['quantity' => 3],
        ]);

        return compact('contabilidad', 'inventario', 'facturacion', 'companias', 'pyme');
    }
}

if (! function_exists('requestPayload')) {
    /** Lo que manda el configurador al pedir una licencia. */
    function requestPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'Ana Mora',
            'company_name' => 'Distribuidora La Sabana S.A.',
            'identification' => '3-101-123456',
            'email' => 'ana@lasabana.test',
            'phone' => '8888-8888',
            'message' => 'Queremos empezar en enero.',
            'consent' => 1,
            'website' => '',
            'package' => 'pyme',
            'cycle' => 'monthly',
            'currency' => 'CRC',
            'selection' => ['contabilidad' => 1, 'inventario' => 1, 'facturacion' => 1, 'companias' => 3],
            // Abierta hace un minuto: pasa el tiempo mínimo.
            'started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ], $overrides);
    }
}
