<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Models\PricingPackage;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los paquetes de los planes (CLAUDE.md secc. 42): un nombre, sus funciones
 * con su cantidad, cómo se cobra y un descuento opcional. Las obligatorias y
 * lo que las elegidas necesitan se suman solos al guardar, como en el
 * configurador del sitio.
 */
class PricingPackageController extends Controller
{
    use RecordsPropietarioAudit;

    private const FIELDS = ['name', 'slug', 'tagline', 'description', 'badge', 'is_highlighted', 'billing', 'discount_percent', 'is_active', 'sort_order'];

    public function store(Request $request): RedirectResponse
    {
        $package = new PricingPackage;

        DB::transaction(function () use ($request, $package) {
            ['fields' => $fields, 'features' => $features] = $this->validated($request, $package);

            $package->fill($fields)->save();
            $package->features()->sync($features);

            $this->auditPropietario($request, 'pricing_package_created', $package, null, $this->auditSnapshot($package, self::FIELDS) + ['funciones' => $features]);
        });

        return back()->with('success', "El paquete {$package->name} quedó creado.");
    }

    public function update(Request $request, PricingPackage $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package) {
            ['fields' => $fields, 'features' => $features] = $this->validated($request, $package);

            $before = $package->only(self::FIELDS) + ['funciones' => $this->currentFeatures($package)];
            $package->fill($fields)->save();
            $package->features()->sync($features);
            [$old, $new] = $this->changedValues($before, $package->only(self::FIELDS) + ['funciones' => $features]);

            if ($new) {
                $this->auditPropietario($request, 'pricing_package_updated', $package, $old, $new);
            }
        });

        return back()->with('success', "Los cambios del paquete {$package->name} quedaron guardados.");
    }

    public function destroy(Request $request, PricingPackage $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package) {
            $this->auditPropietario($request, 'pricing_package_deleted', $package, $this->auditSnapshot($package, self::FIELDS));
            $package->delete();
        });

        return back()->with('success', "El paquete {$package->name} se eliminó. Las solicitudes que lo pidieron conservan su cotización.");
    }

    /**
     * @return array{fields: array<string, mixed>, features: array<int, array{quantity: int}>}
     */
    private function validated(Request $request, PricingPackage $package): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('pricing_packages', 'name')->ignore($package->id)],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'badge' => ['nullable', 'string', 'max:40'],
            'is_highlighted' => ['boolean'],
            'billing' => ['required', Rule::in(PricingPackage::BILLING)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['integer', 'min:0', 'max:9999'],
        ], [
            'features.required' => 'Elegí las funciones que trae el paquete.',
            'features.min' => 'Elegí las funciones que trae el paquete.',
        ], ['name' => 'nombre', 'tagline' => 'frase', 'badge' => 'distintivo', 'discount_percent' => 'descuento']);

        $features = PricingFeature::query()->with('requirements:id')->get()->keyBy('id');
        $chosen = [];

        foreach ($validated['features'] as $id => $quantity) {
            $feature = $features->get((int) $id);

            if ($feature === null || (int) $quantity <= 0) {
                continue;
            }

            $chosen[$feature->id] = $feature->isQuantity()
                ? min((int) $quantity, $feature->max_quantity ?? PHP_INT_MAX)
                : 1;
        }

        if ($chosen === []) {
            throw ValidationException::withMessages(['features' => 'Elegí las funciones que trae el paquete.']);
        }

        // Las obligatorias y lo que necesitan las elegidas, solas.
        foreach ($features as $feature) {
            if ($feature->is_required && ! isset($chosen[$feature->id])) {
                $chosen[$feature->id] = $feature->isQuantity() ? max(1, $feature->included_quantity) : 1;
            }
        }

        do {
            $changed = false;

            foreach (array_keys($chosen) as $id) {
                foreach ($features[$id]->requirements as $required) {
                    if (! isset($chosen[$required->id])) {
                        $needed = $features[$required->id];
                        $chosen[$required->id] = $needed->isQuantity() ? max(1, $needed->included_quantity) : 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return [
            'fields' => [
                'name' => trim($validated['name']),
                'slug' => $package->exists && $package->name === trim($validated['name']) ? $package->slug : $this->slug($validated['name'], $package),
                'tagline' => self::nullable($validated['tagline'] ?? null),
                'description' => self::nullable($validated['description'] ?? null),
                'badge' => self::nullable($validated['badge'] ?? null),
                'is_highlighted' => (bool) ($validated['is_highlighted'] ?? false),
                'billing' => $validated['billing'],
                'discount_percent' => $validated['discount_percent'] ?? 0,
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ],
            'features' => collect($chosen)->map(fn (int $quantity) => ['quantity' => $quantity])->all(),
        ];
    }

    /** @return array<int, array{quantity: int}> */
    private function currentFeatures(PricingPackage $package): array
    {
        return $package->features()->get()->mapWithKeys(fn (PricingFeature $feature) => [$feature->id => ['quantity' => (int) $feature->pivot->quantity]])->all();
    }

    private function slug(string $name, PricingPackage $package): string
    {
        $base = Str::slug($name) ?: 'paquete';
        $slug = $base;

        for ($n = 2; PricingPackage::query()->where('slug', $slug)->when($package->exists, fn ($query) => $query->whereKeyNot($package->id))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
