<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CompanyLogo;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\UploadedImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El logo de la compañía activa: el que sale en el encabezado de sus
 * reportes. Se administra en Administración → Apariencia.
 *
 * Solo el Superusuario. El tema lo cambian también los Administradores, pero
 * el logo es la identidad de la compañía en los documentos que entrega: lo
 * decide su dueño. La regla se comprueba acá, en cada acción, y no solo
 * escondiendo el botón (CLAUDE.md secc. 18).
 *
 * Va al disco de imágenes (MediaStorage: el bucket de Cloudflare R2) como
 * «companies/user_owner_{dueño}/company_logo_{compañía}.{ext}»: uno por
 * compañía, con nombre fijo, agrupado por el dueño de la licencia.
 *
 * No queda exento del modo de gracia (EnforceLicenseGracePeriod): a
 * diferencia de «Mi cuenta», esto sí es un dato de la compañía.
 */
class CompanyLogoController extends Controller
{
    public function update(Request $request, CurrentCompany $currentCompany, MediaStorage $media): RedirectResponse
    {
        $company = $this->companyOwnedBy($request, $currentCompany);

        $megabytes = intdiv(CompanyLogo::MAX_KILOBYTES, 1024);
        $formats = UploadedImage::formatsLabel();

        $request->validate([
            'logo' => [
                'required',
                'image',
                'mimes:'.implode(',', UploadedImage::extensions()),
                'max:'.CompanyLogo::MAX_KILOBYTES,
                // El tope es por memoria: para ajustarlo hay que abrirlo
                // entero. El mínimo se revisa después, ya sin márgenes.
                'dimensions:max_width=6000,max_height=6000',
            ],
        ], [
            'logo.image' => "El archivo tiene que ser una imagen en {$formats}.",
            'logo.mimes' => "El logo tiene que estar en {$formats}.",
            'logo.max' => "El logo no puede pesar más de {$megabytes} MB.",
            'logo.dimensions' => 'El logo no puede medir más de 6000 píxeles de lado.',
            'logo.uploaded' => 'No se pudo subir el logo. Probá con un archivo más liviano.',
        ], ['logo' => 'logo']);

        $logo = CompanyLogo::normalize($request->file('logo'));

        if ($logo === null) {
            return back()->withErrors(['logo' => "No pudimos usar esa imagen: no se pudo leer o está en blanco. Probá con otra en {$formats}."]);
        }

        if (max($logo['width'], $logo['height']) < CompanyLogo::MIN_LONG_SIDE) {
            return back()->withErrors(['logo' => 'Ese logo es muy chico y se vería borroso al imprimir. Subí uno de al menos '.CompanyLogo::MIN_LONG_SIDE.' píxeles en su lado más largo.']);
        }

        $path = $this->pathFor($request, $company, $logo['extension']);

        if (! $media->put($path, $logo['contents'])) {
            return back()->withErrors(['logo' => 'No se pudo guardar el logo. Probá de nuevo en un momento.']);
        }

        $previous = $company->logo_path;

        DB::transaction(function () use ($company, $path, $previous, $logo, $request) {
            $company->update(['logo_path' => $path, 'logo_updated_at' => now()]);

            $this->audit($request, $company, 'company.logo_updated',
                $previous === null ? null : ['logo_path' => $previous],
                ['logo_path' => $path, 'width' => $logo['width'], 'height' => $logo['height']],
            );
        });

        // Con la misma ruta, el nuevo ya pisó al anterior. Con otra (cambió
        // el formato, o venía de una ruta vieja) el anterior se borra, para
        // que cada compañía tenga un solo logo en el bucket.
        if ($previous !== null && $previous !== $path) {
            $media->delete($previous);
        }

        return back()->with('success', 'El logo quedó guardado. Ya sale en los reportes de '.($company->trade_name ?: $company->legal_name).'.');
    }

    public function destroy(Request $request, CurrentCompany $currentCompany, MediaStorage $media): RedirectResponse
    {
        $company = $this->companyOwnedBy($request, $currentCompany);
        $previous = $company->logo_path;

        if ($previous === null) {
            return back();
        }

        DB::transaction(function () use ($company, $previous, $request) {
            $company->update(['logo_path' => null, 'logo_updated_at' => null]);

            $this->audit($request, $company, 'company.logo_removed', ['logo_path' => $previous], null);
        });

        // Después de soltarlo en la base: si el bucket no responde, el
        // archivo queda huérfano, pero los reportes ya salen sin logo.
        $media->delete($previous);

        return back()->with('success', 'Quitamos el logo. Los reportes salen solo con el nombre de la compañía.');
    }

    /**
     * La compañía activa, si quien pide es su Superusuario. Un Administrador
     * recibe 403 aunque pueda entrar a Apariencia.
     */
    private function companyOwnedBy(Request $request, CurrentCompany $currentCompany): Company
    {
        $company = Company::findOrFail($currentCompany->id());

        abort_unless($request->user()->isSuperAdmin($company->id), 403, 'Solo el Superusuario de la compañía puede cambiar su logo.');

        return $company;
    }

    /**
     * «companies/user_owner_{dueño}/company_logo_{compañía}.{ext}». El dueño
     * es el de la licencia de la compañía —que es quien está subiendo el
     * logo—; una compañía sin licencia (datos de seeder) usa a quien lo sube.
     */
    private function pathFor(Request $request, Company $company, string $extension): string
    {
        $ownerId = $company->license?->superuser_id ?? $request->user()->id;

        return "companies/user_owner_{$ownerId}/company_logo_{$company->id}.{$extension}";
    }

    private function audit(Request $request, Company $company, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
