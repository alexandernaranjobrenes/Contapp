<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GlDeterminationController extends Controller
{
    public function index(): Response
    {
        $determinations = GlDetermination::with([
            'account:id,code,description_es',
            'costAllocationRule:id,code,name',
        ])->get();

        // scope_id es polimórfico (no hay FK que Eloquent pueda seguir), así
        // que el nombre del alcance se resuelve acá contra los tres catálogos.
        $scopeNames = [
            'item' => Item::pluck('code', 'id'),
            'item_group' => ItemGroup::pluck('code', 'id'),
            'warehouse' => Warehouse::pluck('code', 'id'),
        ];

        return Inertia::render('Inventory/GlDeterminations/Index', [
            'determinations' => $determinations->map(fn (GlDetermination $d) => [
                'id' => $d->id,
                'scope_level' => $d->scope_level,
                'scope_id' => $d->scope_id,
                'scope_name' => $d->scope_level === 'company'
                    ? 'Toda la compañía'
                    : ($scopeNames[$d->scope_level][$d->scope_id] ?? '(eliminado)'),
                'category' => $d->category,
                'account_id' => $d->account_id,
                'account_label' => $d->account?->code.' — '.$d->account?->description_es,
                'cost_allocation_rule_id' => $d->cost_allocation_rule_id,
                'cost_allocation_rule_label' => $d->costAllocationRule?->code,
            ])->values(),
            'scopeLevels' => GlDetermination::SCOPE_LEVELS,
            'categories' => GlDetermination::CATEGORIES,
            'items' => Item::orderBy('code')->get(['id', 'code', 'name']),
            'itemGroups' => ItemGroup::orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->orderBy('code')->get(['id', 'code', 'description_es']),
            'costAllocationRules' => CostAllocationRule::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        $duplicate = GlDetermination::where('company_id', $companyId)
            ->where('scope_level', $validated['scope_level'])
            ->where('scope_id', $validated['scope_id'])
            ->where('category', $validated['category'])
            ->exists();

        if ($duplicate) {
            return back()->withErrors([
                'category' => 'Ya existe una regla para esa categoría en ese mismo alcance. Editá la existente en vez de duplicarla.',
            ])->withInput();
        }

        GlDetermination::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', 'Regla de determinación creada.');
    }

    /**
     * Alcance y categoría no se editan: cambiarlos convertiría la regla en
     * otra distinta y podría chocar con una existente. Solo se corrige a qué
     * cuenta apunta.
     */
    public function update(Request $request, int $glDetermination, CurrentCompany $currentCompany): RedirectResponse
    {
        $glDetermination = GlDetermination::findOrFail($glDetermination);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'account_id' => $this->accountRule($companyId),
            'cost_allocation_rule_id' => $this->costAllocationRule($companyId),
        ]);

        $glDetermination->update([
            'account_id' => $validated['account_id'],
            'cost_allocation_rule_id' => $validated['cost_allocation_rule_id'] ?? null,
        ]);

        return back()->with('success', 'Regla de determinación actualizada.');
    }

    public function destroy(int $glDetermination): RedirectResponse
    {
        GlDetermination::findOrFail($glDetermination)->delete();

        return back()->with('success', 'Regla de determinación eliminada.');
    }

    private function validated(Request $request, int $companyId): array
    {
        $validated = $request->validate([
            'scope_level' => ['required', Rule::in(array_keys(GlDetermination::SCOPE_LEVELS))],
            'scope_id' => ['nullable', 'integer'],
            'category' => ['required', Rule::in(array_keys(GlDetermination::CATEGORIES))],
            'account_id' => $this->accountRule($companyId),
            'cost_allocation_rule_id' => $this->costAllocationRule($companyId),
        ]);

        $validated['scope_id'] = $validated['scope_level'] === 'company'
            ? null
            : ($validated['scope_id'] ?? null);

        if ($validated['scope_level'] !== 'company') {
            if ($validated['scope_id'] === null) {
                throw ValidationException::withMessages([
                    'scope_id' => 'Elegí a qué '.strtolower(GlDetermination::SCOPE_LEVELS[$validated['scope_level']]).' aplica la regla.',
                ]);
            }

            $exists = match ($validated['scope_level']) {
                'item' => Item::whereKey($validated['scope_id'])->exists(),
                'item_group' => ItemGroup::whereKey($validated['scope_id'])->exists(),
                'warehouse' => Warehouse::whereKey($validated['scope_id'])->exists(),
            };

            if (! $exists) {
                throw ValidationException::withMessages([
                    'scope_id' => 'El alcance elegido no existe en esta compañía.',
                ]);
            }
        }

        $validated['cost_allocation_rule_id'] ??= null;

        return $validated;
    }

    /**
     * Guarda de configuración, no de contabilización: exigir cuenta hoja acá
     * evita que el error aparezca recién al intentar mover stock. La
     * autoridad sigue siendo PostJournalService, que lo valida igual.
     */
    private function accountRule(int $companyId): array
    {
        return [
            'required',
            Rule::exists('chart_of_accounts', 'id')
                ->where('company_id', $companyId)
                ->where('accepts_posting', true),
        ];
    }

    private function costAllocationRule(int $companyId): array
    {
        return [
            'nullable',
            Rule::exists('cost_allocation_rules', 'id')->where('company_id', $companyId),
        ];
    }
}
