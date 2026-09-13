<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Exceptions\NotLicenseSuperuserException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\CompanyProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flujo autenticado (a diferencia de LicenseActivationController, que es
 * público/guest): permite al Superusuario dueño de la licencia agregar
 * compañías adicionales bajo su propia cuenta, sin crear un usuario nuevo.
 */
class CompanyProvisioningController extends Controller
{
    public function create(Request $request): Response
    {
        $license = $this->licenseForCurrentCompany();

        abort_if($license === null || $license->superuser_id !== $request->user()->id, 403);

        return Inertia::render('Companies/Create', [
            'license' => [
                'masked_code' => $license->maskedCode(),
                'max_companies' => $license->max_companies,
                'companies_count' => $license->companies()->count(),
                'can_activate_another' => $license->canActivateAnotherCompany(),
            ],
        ]);
    }

    public function store(Request $request, CompanyProvisioningService $service): RedirectResponse
    {
        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ]);

        $license = $this->licenseForCurrentCompany();

        abort_if($license === null, 403);

        try {
            $company = $service->createAdditionalCompany($request->user(), $license, $validated);
        } catch (InvalidLicenseException|NotLicenseSuperuserException $e) {
            return back()->withErrors(['legal_name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dashboard')->with('success', "Compañía {$company->legal_name} creada.");
    }

    /**
     * Todas las compañías de un mismo Superusuario comparten license_id por
     * construcción, así que no importa cuál esté activa en la sesión.
     */
    private function licenseForCurrentCompany(): ?License
    {
        $companyId = app(CurrentCompany::class)->id();

        return $companyId ? Company::find($companyId)?->license : null;
    }
}
