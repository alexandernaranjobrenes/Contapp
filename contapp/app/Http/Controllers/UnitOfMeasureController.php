<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/UnitsOfMeasure/Index', [
            'unitsOfMeasure' => UnitOfMeasure::orderBy('code')->get([
                'id', 'code', 'name', 'decimals', 'status',
            ]),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'min:0', 'max:6'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if (UnitOfMeasure::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe una unidad de medida con el código {$validated['code']}."])->withInput();
        }

        UnitOfMeasure::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Unidad de medida {$validated['code']} creada.");
    }

    /**
     * El código no se edita: los artículos la referencian y las plantillas de
     * importación de Fase 2 la resolverán por código, mismo criterio que
     * CostCenterController.
     */
    public function update(Request $request, int $unitOfMeasure): RedirectResponse
    {
        $unitOfMeasure = UnitOfMeasure::findOrFail($unitOfMeasure);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'min:0', 'max:6'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $unitOfMeasure->update($validated);

        return back()->with('success', "Unidad de medida {$unitOfMeasure->code} actualizada.");
    }

    public function destroy(int $unitOfMeasure): RedirectResponse
    {
        $unitOfMeasure = UnitOfMeasure::findOrFail($unitOfMeasure);

        if (Item::where('uom_id', $unitOfMeasure->id)->exists()) {
            return back()->withErrors([
                'unit_of_measure' => "La unidad de medida {$unitOfMeasure->code} está en uso por uno o más artículos; no se puede eliminar. Podés inactivarla en su lugar.",
            ]);
        }

        $unitOfMeasure->delete();

        return back()->with('success', "Unidad de medida {$unitOfMeasure->code} eliminada.");
    }
}
