<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Models\LicenseCategory;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de categorías de licencia (CLAUDE.md secc. 13). Protegido por el
 * guard 'propietario' en routes/web.php — es configuración del Propietario,
 * invisible para cualquier cliente. Cada cambio queda en la bitácora (ver
 * RecordsPropietarioAudit).
 */
class LicenseCategoryController extends Controller
{
    use RecordsPropietarioAudit;

    private const FIELDS = ['name', 'max_companies', 'max_admins', 'max_users', 'duration_months', 'description', 'is_active'];

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

        DB::transaction(function () use ($request, $validated) {
            $category = LicenseCategory::create($validated);

            $this->auditPropietario($request, 'license_category_created', $category, null, $this->auditSnapshot($category, self::FIELDS));
        });

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

        DB::transaction(function () use ($request, $category, $validated) {
            $before = $category->only(self::FIELDS);
            $category->update($validated);
            [$old, $new] = $this->changedValues($before, $category->only(self::FIELDS));

            if ($new) {
                $this->auditPropietario($request, 'license_category_updated', $category, $old, $new);
            }
        });

        return back()->with('success', "Categoría {$category->name} actualizada.");
    }

    public function destroy(Request $request, int $licenseCategory): RedirectResponse
    {
        $category = LicenseCategory::findOrFail($licenseCategory);

        if ($category->licenses()->exists()) {
            return back()->withErrors([
                'category' => "La categoría {$category->name} ya tiene licencias emitidas; no se puede eliminar. Podés desactivarla en su lugar.",
            ]);
        }

        // La foto va en old_values: la categoría deja de existir, y sin ella
        // la entrada diría "se eliminó la categoría 7" sin decir cuál era.
        DB::transaction(function () use ($request, $category) {
            $this->auditPropietario($request, 'license_category_deleted', $category, $this->auditSnapshot($category, self::FIELDS));
            $category->delete();
        });

        return back()->with('success', "Categoría {$category->name} eliminada.");
    }
}
