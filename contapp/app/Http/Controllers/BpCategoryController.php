<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo plano de categorías de socios de negocio (clientes/proveedores),
 * para poder agrupar/filtrar reportes de ventas por categoría — mismo
 * criterio de catálogo simple que cost_centers tenía antes de ganar
 * jerarquía (docs/decisiones.md, 2026-08-16): solo code+name, sin niveles.
 * A diferencia de tax_rates, bp_categories SÍ tiene company_id (cada
 * compañía arma sus propias categorías), así que no hay gate del Propietario
 * — cualquier usuario de la compañía activa administra su propio catálogo,
 * igual que cost-centers.
 */
class BpCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('BusinessPartners/Categories', [
            'categories' => BpCategory::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('bp_categories', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
        ]);

        BpCategory::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Categoría {$validated['code']} creada.");
    }

    public function update(Request $request, int $bpCategory, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $category = BpCategory::findOrFail($bpCategory);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('bp_categories', 'code')->where('company_id', $companyId)->ignore($category->id)],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $category->update($validated);

        return back()->with('success', "Categoría {$category->code} actualizada.");
    }

    public function destroy(int $bpCategory): RedirectResponse
    {
        $category = BpCategory::findOrFail($bpCategory);

        if (BusinessPartner::where('category_id', $category->id)->exists()) {
            return back()->withErrors([
                'bp_category' => "La categoría {$category->code} está asignada a uno o más socios de negocio; reasignalos primero.",
            ]);
        }

        $category->delete();

        return back()->with('success', "Categoría {$category->code} eliminada.");
    }
}
