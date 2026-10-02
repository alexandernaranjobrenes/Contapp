<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CompanyLogo;
use App\Domains\Core\Support\CompanyTheme;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\UploadedImage;
use App\Domains\Reporting\Support\ReportLogo;
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
 *
 * La misma pantalla muestra el logo de la compañía, que se sube y se quita
 * por CompanyLogoController (solo el Superusuario).
 */
class CompanyAppearanceController extends Controller
{
    public function edit(Request $request, CurrentCompany $currentCompany, MediaStorage $media): Response
    {
        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Appearance/Index', [
            'themes' => CompanyTheme::options(),
            'current' => CompanyTheme::resolve($company->theme)->value,
            'companyName' => $company->trade_name ?: $company->legal_name,
            // El logo de los reportes (CompanyLogoController). Lo ven
            // Superusuario y Administradores; lo cambia solo el Superusuario.
            'logo' => [
                'url' => $media->versionedUrl($company->logo_path, $company->logo_updated_at),
                'can_edit' => $request->user()->isSuperAdmin($company->id),
                // Qué se puede subir y a qué se ajusta, dicho por el servidor
                // para que la pantalla no prometa otra cosa.
                'accept' => implode(',', UploadedImage::mimeTypes()),
                'formats' => UploadedImage::formatsLabel(),
                'max_megabytes' => intdiv(CompanyLogo::MAX_KILOBYTES, 1024),
                'max_width' => CompanyLogo::MAX_WIDTH,
                'max_height' => CompanyLogo::MAX_HEIGHT,
                // La caja del encabezado de un reporte, para la vista previa.
                'report_box' => ['width' => ReportLogo::BOX_WIDTH, 'height' => ReportLogo::BOX_HEIGHT],
            ],
            // Lo que acompaña al logo en ese encabezado.
            'reportHeader' => [
                'name' => $company->trade_name ?: $company->legal_name,
                'tax_id' => $company->tax_id,
                'address' => $company->address,
            ],
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
