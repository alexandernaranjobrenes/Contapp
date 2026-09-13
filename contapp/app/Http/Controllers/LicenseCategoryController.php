<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Models\LicenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de categorías de licencia (CLAUDE.md secc. 13). Protegido por el
 * guard 'propietario' en routes/web.php — es configuración del Propietario,
 * invisible para cualquier cliente.
 */
class LicenseCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Backoffice/LicenseCategories/Index', [
            'categories' => LicenseCategory::withCount('licenses')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('license_categories', 'name')],
            'max_companies' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_admins' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_users' => ['required', 'integer', 'min:0', 'max:1000'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        LicenseCategory::create($validated);

        return back()->with('success', "Categoría {$validated['name']} creada.");
    }

    public function update(Request $request, int $licenseCategory): RedirectResponse
    {
        $category = LicenseCategory::findOrFail($licenseCategory);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('license_categories', 'name')->ignore($category->id)],
            'max_companies' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_admins' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_users' => ['required', 'integer', 'min:0', 'max:1000'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $category->update($validated);

        return back()->with('success', "Categoría {$category->name} actualizada.");
    }

    public function destroy(int $licenseCategory): RedirectResponse
    {
        $category = LicenseCategory::findOrFail($licenseCategory);

        if ($category->licenses()->exists()) {
            return back()->withErrors([
                'category' => "La categoría {$category->name} ya tiene licencias emitidas; no se puede eliminar. Podés desactivarla en su lugar.",
            ]);
        }

        $category->delete();

        return back()->with('success', "Categoría {$category->name} eliminada.");
    }
}
