<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Models\PricingPackage;
use App\Domains\Pricing\Models\PricingSetting;
use App\Domains\Pricing\Support\PricingCatalog;
use App\Domains\Pricing\Support\PricingDefaults;
use App\Domains\Site\Support\SiteIcons;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planes y precios desde el backoffice (CLAUDE.md secc. 42): los ajustes
 * (moneda, IVA, adónde llegan las solicitudes), las funciones con su precio
 * y los paquetes. Las funciones y los paquetes tienen su controlador
 * (PricingFeatureController, PricingPackageController). Cada cambio queda en
 * la bitácora del backoffice.
 */
class PricingController extends Controller
{
    use RecordsPropietarioAudit;

    private const SETTINGS = ['default_currency', 'tax_rate', 'prices_include_tax', 'notification_email'];

    public function index(): Response
    {
        $settings = PricingSetting::current();

        $features = PricingFeature::query()->ordered()->with('requirements:id')->withCount('packages')->get();
        $packages = PricingPackage::query()->ordered()->with('features:id')->get();

        return Inertia::render('Backoffice/Pricing/Index', [
            'settings' => [
                'default_currency' => $settings->default_currency,
                'tax_rate' => (float) $settings->tax_rate,
                'prices_include_tax' => $settings->prices_include_tax,
                'notification_email' => $settings->notification_email,
            ],
            'features' => $features->map(fn (PricingFeature $feature) => [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'description' => $feature->description,
                'icon' => $feature->icon,
                'icon_svg' => SiteIcons::exists($feature->icon) ? SiteIcons::svg($feature->icon, 18) : SiteIcons::svg('sparkles', 18),
                'group' => $feature->group,
                'kind' => $feature->kind,
                'unit_singular' => $feature->unit_singular,
                'unit_plural' => $feature->unit_plural,
                'included_quantity' => $feature->included_quantity,
                'max_quantity' => $feature->max_quantity,
                'monthly_crc' => (float) $feature->monthly_crc,
                'annual_crc' => $feature->annual_crc === null ? null : (float) $feature->annual_crc,
                'monthly_usd' => (float) $feature->monthly_usd,
                'annual_usd' => $feature->annual_usd === null ? null : (float) $feature->annual_usd,
                'is_required' => $feature->is_required,
                'is_active' => $feature->is_active,
                'module_key' => $feature->module_key,
                'sort_order' => $feature->sort_order,
                'requires' => $feature->requirements->pluck('id')->all(),
                'packages_count' => $feature->packages_count,
            ]),
            'packages' => $packages->map(fn (PricingPackage $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'slug' => $package->slug,
                'tagline' => $package->tagline,
                'description' => $package->description,
                'badge' => $package->badge,
                'is_highlighted' => $package->is_highlighted,
                'billing' => $package->billing,
                'discount_percent' => (float) $package->discount_percent,
                'is_active' => $package->is_active,
                'sort_order' => $package->sort_order,
                'features' => $package->features->mapWithKeys(fn (PricingFeature $feature) => [$feature->id => (int) $feature->pivot->quantity])->all() ?: (object) [],
            ]),
            // Para calcular el precio de un paquete mientras se arma: todas
            // las funciones, también las inactivas.
            'catalog' => PricingCatalog::forBackoffice($settings),
            'icons' => SiteIcons::pickable(),
            'modules' => PricingDefaults::modules(),
            'siteUrl' => route('pricing.index'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_currency' => ['required', Rule::in(PricingSetting::CURRENCIES)],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'prices_include_tax' => ['boolean'],
            'notification_email' => ['required', 'email:rfc', 'max:190'],
        ], [], [
            'default_currency' => 'moneda',
            'tax_rate' => 'IVA',
            'notification_email' => 'correo de avisos',
        ]);

        $settings = PricingSetting::current();

        DB::transaction(function () use ($request, $settings, $validated) {
            $before = $settings->only(self::SETTINGS);
            $settings->update($validated);
            [$old, $new] = $this->changedValues($before, $settings->only(self::SETTINGS));

            if ($new) {
                $this->auditPropietario($request, 'pricing_settings_updated', $settings, $old, $new);
            }
        });

        return back()->with('success', 'Los ajustes de los precios quedaron guardados.');
    }

    /** «Cargar funciones sugeridas»: las de los módulos de Pivox, sin precio e inactivas. */
    public function loadDefaults(Request $request): RedirectResponse
    {
        $created = PricingDefaults::load();

        if ($created > 0) {
            $this->auditPropietario($request, 'pricing_defaults_loaded', PricingSetting::current(), null, ['funciones' => $created]);
        }

        return back()->with('success', $created > 0
            ? "Se cargaron {$created} funciones sugeridas, sin precio e inactivas: ponele precio a cada una y activala."
            : 'Las funciones sugeridas ya estaban cargadas.');
    }
}
