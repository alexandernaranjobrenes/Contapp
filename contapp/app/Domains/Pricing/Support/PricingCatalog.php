<?php

namespace App\Domains\Pricing\Support;

use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Models\PricingPackage;
use App\Domains\Pricing\Models\PricingSetting;
use App\Domains\Site\Support\SiteIcons;

/**
 * El catálogo de precios en la forma que usan el servidor y el navegador
 * (CLAUDE.md secc. 42): PricingCalculator (PHP) y resources/js/Utils/pricing.js
 * calculan sobre estas mismas estructuras, con los montos en céntimos
 * enteros, para que la cotización que se ve y la que se guarda sean la
 * misma.
 *
 *     features: [{ key, name, description, group, icon, icon_svg, kind,
 *                  unit_singular, unit_plural, included, max, required,
 *                  requires: [keys], prices: { CRC: { monthly, annual },
 *                  USD: { monthly, annual } } }]
 *     packages: [{ slug, name, tagline, description, badge, highlighted,
 *                  billing, discount_bp, features: { key: cantidad } }]
 *     settings: { currency, tax_bp, tax_rate, prices_include_tax }
 *
 * Solo lo activo: una función inactiva no existe para el sitio, tampoco
 * dentro de un paquete.
 */
final class PricingCatalog
{
    /** @return array{features: list<array<string, mixed>>, packages: list<array<string, mixed>>, settings: array<string, mixed>} */
    public static function forSite(?PricingSetting $settings = null): array
    {
        $settings ??= PricingSetting::current();

        $features = PricingFeature::query()->active()->ordered()->with('requirements:id,key,is_active')->get();
        $activeKeys = $features->pluck('key')->all();

        $packages = PricingPackage::query()->active()->ordered()->with('features:id,key,is_active')->get();

        return [
            'features' => $features->map(fn (PricingFeature $feature) => self::feature($feature, $activeKeys))->values()->all(),
            'packages' => $packages->map(fn (PricingPackage $package) => self::package($package, $activeKeys))->values()->all(),
            'settings' => self::settings($settings),
        ];
    }

    /**
     * Para calcular en el backoffice el precio de un paquete mientras se arma:
     * todas las funciones, también las inactivas (se están preparando), y sin
     * paquetes (el que se arma lo pone la pantalla).
     *
     * @return array{features: list<array<string, mixed>>, packages: list<array<string, mixed>>, settings: array<string, mixed>}
     */
    public static function forBackoffice(PricingSetting $settings): array
    {
        $features = PricingFeature::query()->ordered()->with('requirements:id,key')->get();
        $keys = $features->pluck('key')->all();

        return [
            'features' => $features->map(fn (PricingFeature $feature) => self::feature($feature, $keys) + ['id' => $feature->id, 'active' => $feature->is_active])->values()->all(),
            'packages' => [],
            'settings' => self::settings($settings),
        ];
    }

    /**
     * @param  list<string>  $activeKeys
     * @return array<string, mixed>
     */
    public static function feature(PricingFeature $feature, array $activeKeys): array
    {
        $prices = [];

        foreach (PricingSetting::CURRENCIES as $currency) {
            $prices[$currency] = [
                'monthly' => $feature->priceCents('monthly', $currency),
                'annual' => $feature->priceCents('annual', $currency),
            ];
        }

        return [
            'key' => $feature->key,
            'name' => $feature->name,
            'description' => $feature->description,
            'group' => $feature->group,
            'icon' => $feature->icon,
            'icon_svg' => SiteIcons::exists($feature->icon) ? SiteIcons::svg($feature->icon, 20) : SiteIcons::svg('sparkles', 20),
            'kind' => $feature->kind,
            'unit_singular' => $feature->unit_singular,
            'unit_plural' => $feature->unit_plural,
            'included' => $feature->included_quantity,
            'max' => $feature->max_quantity,
            'required' => $feature->is_required,
            'requires' => $feature->requirements
                ->filter(fn (PricingFeature $required) => in_array($required->key, $activeKeys, true))
                ->pluck('key')
                ->values()
                ->all(),
            'prices' => $prices,
        ];
    }

    /**
     * @param  list<string>  $activeKeys
     * @return array<string, mixed>
     */
    public static function package(PricingPackage $package, array $activeKeys): array
    {
        $features = [];

        foreach ($package->features as $feature) {
            if (in_array($feature->key, $activeKeys, true)) {
                $features[$feature->key] = max(1, (int) $feature->pivot->quantity);
            }
        }

        return [
            'slug' => $package->slug,
            'name' => $package->name,
            'tagline' => $package->tagline,
            'description' => $package->description,
            'badge' => $package->badge,
            'highlighted' => $package->is_highlighted,
            'billing' => $package->billing,
            'discount_bp' => $package->discountBasisPoints(),
            'features' => (object) $features,
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(PricingSetting $settings): array
    {
        return [
            'currency' => $settings->default_currency,
            'tax_bp' => $settings->taxBasisPoints(),
            'tax_rate' => (float) $settings->tax_rate,
            'prices_include_tax' => $settings->prices_include_tax,
        ];
    }
}
