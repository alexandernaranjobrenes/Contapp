<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Services\GlDeterminationScopeService;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemGroupController extends Controller
{
    public function index(GlDeterminationScopeService $scopes): Response
    {
        return Inertia::render('Inventory/ItemGroups/Index', [
            'itemGroups' => ItemGroup::orderBy('code')
                ->withCount('items')
                ->get(['id', 'code', 'name', 'status']),
            // Cuentas por grupo: el escalón intermedio de la
            // determinación, entre el artículo y el almacén.
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->description_es]),
            'accountCategories' => collect(GlDeterminationScopeService::CARD_CATEGORIES['item_group'])
                ->mapWithKeys(fn (string $c) => [$c => GlDetermination::CATEGORIES[$c]])
                ->all(),
            'groupAccounts' => $scopes->forScopeLevel(app(CurrentCompany::class)->id(), 'item_group'),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')
                ->where('company_id', $companyId)->where('accepts_posting', true)],
        ]);

        if (ItemGroup::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un grupo de artículos con el código {$validated['code']}."])->withInput();
        }

        $group = ItemGroup::create([
            ...collect($validated)->except('accounts')->all(),
            'company_id' => $companyId,
        ]);

        app(GlDeterminationScopeService::class)
            ->sync($companyId, 'item_group', $group->id, $validated['accounts'] ?? []);

        return back()->with('success', "Grupo {$validated['code']} creado.");
    }

    public function update(Request $request, int $itemGroup): RedirectResponse
    {
        $itemGroup = ItemGroup::findOrFail($itemGroup);

        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')
                ->where('company_id', $companyId)->where('accepts_posting', true)],
        ]);

        $itemGroup->update(collect($validated)->except('accounts')->all());

        app(GlDeterminationScopeService::class)
            ->sync($companyId, 'item_group', $itemGroup->id, $validated['accounts'] ?? []);

        return back()->with('success', "Grupo {$itemGroup->code} actualizado.");
    }

    /**
     * Se bloquea en vez de dejar que el nullOnDelete de la FK vacíe el grupo
     * de los artículos en silencio: en Fase 2 el grupo es uno de los niveles
     * de la matriz de determinación de cuentas, y perderlo cambiaría a qué
     * cuenta contable van esos artículos sin que nadie lo note.
     */
    public function destroy(int $itemGroup): RedirectResponse
    {
        $itemGroup = ItemGroup::findOrFail($itemGroup);

        if (Item::where('item_group_id', $itemGroup->id)->exists()) {
            return back()->withErrors([
                'item_group' => "El grupo {$itemGroup->code} tiene artículos asignados; reasignalos primero, o inactivá el grupo en su lugar.",
            ]);
        }

        $itemGroup->delete();

        return back()->with('success', "Grupo {$itemGroup->code} eliminado.");
    }
}
