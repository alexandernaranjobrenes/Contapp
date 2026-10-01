<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CompanyTheme;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Apariencia: el tema visual de la compañía activa
 * (CLAUDE.md secc. 31). Lo ve todo el que entra a la compañía, así que solo
 * lo cambian su Superusuario y sus Administradores (can-manage-company).
 */
class CompanyAppearanceController extends Controller
{
    public function edit(CurrentCompany $currentCompany): Response
    {
        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Appearance/Index', [
            'themes' => CompanyTheme::options(),
            'current' => CompanyTheme::resolve($company->theme)->value,
            'companyName' => $company->trade_name ?: $company->legal_name,
        ]);
    }

    public function update(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::enum(CompanyTheme::class)],
        ], [
            'theme.required' => 'Elegí un tema.',
            'theme.enum' => 'Ese tema no existe.',
        ]);

        $company = Company::findOrFail($currentCompany->id());
        $previous = CompanyTheme::resolve($company->theme);
        $theme = CompanyTheme::from($validated['theme']);

        if ($previous !== $theme) {
            // El cambio y su rastro van juntos: si la bitácora falla, el tema
            // no cambia.
            DB::transaction(function () use ($company, $previous, $theme, $request) {
                $company->update(['theme' => $theme->value]);

                AuditLog::create([
                    'company_id' => $company->id,
                    'user_id' => $request->user()->id,
                    'action' => 'company.theme_updated',
                    'auditable_type' => Company::class,
                    'auditable_id' => $company->id,
                    'old_values' => ['theme' => $previous->value],
                    'new_values' => ['theme' => $theme->value],
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                ]);
            });
        }

        return redirect()->route('appearance.edit')
            ->with('success', "Tema {$theme->label()} aplicado a ".($company->trade_name ?: $company->legal_name).'.');
    }
}
