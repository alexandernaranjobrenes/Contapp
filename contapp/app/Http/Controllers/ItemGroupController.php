<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ItemGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/ItemGroups/Index', [
            'itemGroups' => ItemGroup::orderBy('code')
                ->withCount('items')
                ->get(['id', 'code', 'name', 'status']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if (ItemGroup::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un grupo de artículos con el código {$validated['code']}."])->withInput();
        }

        ItemGroup::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Grupo {$validated['code']} creado.");
    }

    public function update(Request $request, int $itemGroup): RedirectResponse
    {
        $itemGroup = ItemGroup::findOrFail($itemGroup);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $itemGroup->update($validated);

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
