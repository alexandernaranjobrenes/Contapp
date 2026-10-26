<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Pricing\Models\PricingFeature;
use App\Domains\Pricing\Support\PricingDefaults;
use App\Domains\Site\Support\SiteIcons;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Las funciones de los planes, con su precio (CLAUDE.md secc. 42). Una
 * función que necesita otra no puede terminar necesitándose a sí misma,
 * aunque sea por un camino largo. Eliminar una la quita de sus paquetes; las
 * solicitudes ya recibidas guardan su cotización tal como se vio.
 */
class PricingFeatureController extends Controller
{
    use RecordsPropietarioAudit;

    private const FIELDS = [
        'key', 'name', 'description', 'icon', 'group', 'kind', 'unit_singular', 'unit_plural', 'included_quantity',
        'max_quantity', 'monthly_crc', 'annual_crc', 'monthly_usd', 'annual_usd', 'is_required', 'is_active',
        'module_key', 'sort_order',
    ];

    public function store(Request $request): RedirectResponse
    {
        $feature = new PricingFeature;

        DB::transaction(function () use ($request, $feature) {
            ['fields' => $fields, 'requires' => $requires] = $this->validated($request, $feature);

            $feature->fill($fields)->save();
            $this->syncRequirements($feature, $requires);

            $this->auditPropietario($request, 'pricing_feature_created', $feature, null, $this->auditSnapshot($feature, self::FIELDS));
        });

        return back()->with('success', "La función {$feature->name} quedó creada.");
    }

    public function update(Request $request, PricingFeature $feature): RedirectResponse
    {
        DB::transaction(function () use ($request, $feature) {
            ['fields' => $fields, 'requires' => $requires] = $this->validated($request, $feature);

            $before = $feature->only(self::FIELDS);
            $feature->fill($fields)->save();
            $this->syncRequirements($feature, $requires);
            [$old, $new] = $this->changedValues($before, $feature->only(self::FIELDS));

            if ($new) {
                $this->auditPropietario($request, 'pricing_feature_updated', $feature, $old, $new);
            }
        });

        return back()->with('success', "Los cambios de {$feature->name} quedaron guardados.");
    }

    public function destroy(Request $request, PricingFeature $feature): RedirectResponse
    {
        DB::transaction(function () use ($request, $feature) {
            $this->auditPropietario($request, 'pricing_feature_deleted', $feature, $this->auditSnapshot($feature, self::FIELDS));
            $feature->delete();
        });

        return back()->with('success', "La función {$feature->name} se eliminó.");
    }

    /**
     * @return array{fields: array<string, mixed>, requires: list<int>}
     */
    private function validated(Request $request, PricingFeature $feature): array
    {
        $request->merge(['key' => Str::slug((string) ($request->input('key') ?: $request->input('name')), '_')]);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('pricing_features', 'key')->ignore($feature->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'icon' => ['required', Rule::in(SiteIcons::PICKABLE)],
            'group' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in([PricingFeature::KIND_TOGGLE, PricingFeature::KIND_QUANTITY])],
            'unit_singular' => ['nullable', 'required_if:kind,quantity', 'string', 'max:40'],
            'unit_plural' => ['nullable', 'required_if:kind,quantity', 'string', 'max:40'],
            'included_quantity' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'monthly_crc' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'annual_crc' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'monthly_usd' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'annual_usd' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'module_key' => ['nullable', Rule::in(array_keys(PricingDefaults::modules()))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'requires' => ['array'],
            'requires.*' => ['integer', Rule::exists('pricing_features', 'id')],
        ], [
            'key.unique' => 'Esa clave ya la usa otra función.',
            'key.regex' => 'La clave lleva solo letras minúsculas, números y guiones bajos.',
            'unit_singular.required_if' => 'Una función por cantidad necesita el nombre de su unidad (compañía, usuario…).',
            'unit_plural.required_if' => 'Una función por cantidad necesita el nombre de su unidad en plural.',
        ], [
            'name' => 'nombre',
            'group' => 'grupo',
            'monthly_crc' => 'precio mensual en colones',
            'annual_crc' => 'precio anual en colones',
            'monthly_usd' => 'precio mensual en dólares',
            'annual_usd' => 'precio anual en dólares',
        ]);

        $quantity = $validated['kind'] === PricingFeature::KIND_QUANTITY;

        if ($quantity && isset($validated['max_quantity']) && $validated['max_quantity'] < ($validated['included_quantity'] ?? 0)) {
            throw ValidationException::withMessages(['max_quantity' => 'El máximo no puede ser menor que las incluidas sin costo.']);
        }

        $requires = array_values(array_unique(array_map('intval', $validated['requires'] ?? [])));

        if ($feature->exists && in_array($feature->id, $requires, true)) {
            throw ValidationException::withMessages(['requires' => 'Una función no puede necesitarse a sí misma.']);
        }

        return [
            'fields' => [
                'key' => $validated['key'],
                'name' => trim($validated['name']),
                'description' => self::nullable($validated['description'] ?? null),
                'icon' => $validated['icon'],
                'group' => trim($validated['group']),
                'kind' => $validated['kind'],
                'unit_singular' => $quantity ? self::nullable($validated['unit_singular'] ?? null) : null,
                'unit_plural' => $quantity ? self::nullable($validated['unit_plural'] ?? null) : null,
                'included_quantity' => $quantity ? (int) ($validated['included_quantity'] ?? 0) : 0,
                'max_quantity' => $quantity ? ($validated['max_quantity'] ?? null) : null,
                'monthly_crc' => $validated['monthly_crc'],
                'annual_crc' => $validated['annual_crc'] ?? null,
                'monthly_usd' => $validated['monthly_usd'],
                'annual_usd' => $validated['annual_usd'] ?? null,
                'is_required' => (bool) ($validated['is_required'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'module_key' => $validated['module_key'] ?? null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ],
            'requires' => $requires,
        ];
    }

    /**
     * Guarda lo que necesita, sin ciclos: si alguna de las que necesita ya
     * la necesita a ella (aunque sea a través de otras), no se guarda.
     *
     * @param  list<int>  $requires
     */
    private function syncRequirements(PricingFeature $feature, array $requires): void
    {
        $graph = DB::table('pricing_feature_requirements')
            ->where('feature_id', '!=', $feature->id)
            ->get()
            ->groupBy('feature_id')
            ->map(fn ($rows) => $rows->pluck('required_feature_id')->map(fn ($id) => (int) $id)->all());

        $pending = $requires;
        $seen = [];

        while ($pending !== []) {
            $current = array_pop($pending);

            if ($current === $feature->id) {
                throw ValidationException::withMessages(['requires' => 'Eso arma un círculo: una de las funciones elegidas ya necesita a esta.']);
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            array_push($pending, ...($graph[$current] ?? []));
        }

        $feature->requirements()->sync($requires);
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
