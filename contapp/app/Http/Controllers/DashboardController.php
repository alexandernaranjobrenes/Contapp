<?php

namespace App\Http\Controllers;

use App\Domains\Core\Dashboard\DashboardService;
use App\Domains\Core\Dashboard\DashboardWidgetCatalog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El Panel (CLAUDE.md secc. 44): los elementos que la compañía eligió, solo
 * los que esta persona puede ver, con sus datos. Quien lo puede cambiar
 * (Superusuario o Administrador) recibe además el catálogo de lo que puede
 * agregar.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany, DashboardService $dashboard): Response
    {
        if (! $currentCompany->isSet()) {
            return Inertia::render('Dashboard', ['noCompany' => true]);
        }

        $company = Company::findOrFail($currentCompany->id());
        $user = $request->user();
        $canEdit = $dashboard->canEdit($user, $company);

        return Inertia::render('Dashboard', [
            'noCompany' => false,
            'widgets' => $dashboard->panel($user, $company),
            'canEdit' => $canEdit,
            'editor' => $canEdit ? [
                ...$dashboard->editor($user, $company),
                'max' => DashboardWidgetCatalog::MAX_WIDGETS,
            ] : null,
        ]);
    }

    public function update(Request $request, CurrentCompany $currentCompany, DashboardService $dashboard): RedirectResponse
    {
        $validated = $request->validate([
            'widgets' => ['present', 'array', 'max:'.DashboardWidgetCatalog::MAX_WIDGETS],
            'widgets.*' => ['string', 'max:60'],
        ], [
            'widgets.max' => 'El Panel lleva hasta '.DashboardWidgetCatalog::MAX_WIDGETS.' elementos.',
        ]);

        $company = Company::findOrFail($currentCompany->id());
        $dashboard->save($request->user(), $company, $validated['widgets'], $request->ip());

        return redirect()->route('dashboard')->with('success', 'Panel guardado.');
    }
}
