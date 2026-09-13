<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Licensing\Services\LicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Protegido por el guard 'propietario' (routes/web.php, prefijo /backoffice):
 * License es global, sin CompanyScope, a propósito — el dueño de CONTAPP
 * necesita ver/emitir licencias de TODAS las compañías, no de una sola.
 */
class LicenseController extends Controller
{
    public function index(): Response
    {
        $licenses = License::withCount('companies')
            ->with(['issuedBy:id,name', 'category:id,name', 'commercialProfile.followUps', 'superuser:id,name,email'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (License $license) => [
                ...$license->toArray(),
                'masked_code' => $license->maskedCode(),
                'display_status' => $license->displayStatus(),
                'next_pending_follow_up' => $license->commercialProfile?->nextPendingFollowUp(),
                'admins_count' => $license->adminsCount(),
                'users_count' => $license->usersCount(),
            ]);

        return Inertia::render('Backoffice/Licenses/Index', [
            'licenses' => $licenses,
            'categories' => LicenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name', 'max_companies', 'max_admins', 'max_users', 'duration_months']),
        ]);
    }

    public function store(Request $request, LicenseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:license_categories,id'],
            'expires_at' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $category = LicenseCategory::findOrFail($validated['category_id']);

        $license = $service->issue(
            $category,
            new \DateTime($validated['expires_at']),
            $validated['notes'] ?? null,
            $request->user('propietario')->id,
        );

        return back()->with('success', "Licencia {$license->code} emitida.");
    }

    public function update(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:license_categories,id'],
            'max_companies' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_admins' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_users' => ['required', 'integer', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->update($license, $validated);
        } catch (InvalidLicenseException $e) {
            return back()->withErrors(['license' => $e->getMessage()]);
        }

        return back()->with('success', "Licencia {$license->code} actualizada.");
    }

    public function renew(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'expires_at' => ['required', 'date', 'after:today'],
        ]);

        $license = License::findOrFail($license);
        $service->renew($license, new \DateTime($validated['expires_at']));

        return back()->with('success', "Licencia {$license->code} renovada.");
    }

    public function revoke(int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $service->revoke($license);

        return back()->with('success', "Licencia {$license->code} revocada.");
    }

    public function suspend(int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $service->suspend($license);

        return back()->with('success', "Licencia {$license->code} suspendida.");
    }

    public function reactivate(int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $service->reactivate($license);

        return back()->with('success', "Licencia {$license->code} reactivada.");
    }
}
