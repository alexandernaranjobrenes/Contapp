<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Models\CommercialProfile;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha comercial de un cliente (CLAUDE.md secc. 15). Protegido por el guard
 * 'propietario' en routes/web.php, igual que el resto del backoffice del
 * Propietario.
 */
class CommercialProfileController extends Controller
{
    public function show(int $license): Response
    {
        $license = License::with([
            'category:id,name',
            'commercialProfile.interactions.author:id,name',
            'commercialProfile.followUps',
        ])->findOrFail($license);

        return Inertia::render('Backoffice/Licenses/CommercialProfile', [
            'license' => [
                'id' => $license->id,
                'masked_code' => $license->maskedCode(),
                'display_status' => $license->displayStatus(),
                'category' => $license->category,
            ],
            'profile' => $license->commercialProfile,
        ]);
    }

    public function store(Request $request, int $license): RedirectResponse
    {
        $license = License::findOrFail($license);

        $validated = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'commercial_email' => ['nullable', 'email', 'max:255'],
            'referral_source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        CommercialProfile::updateOrCreate(['license_id' => $license->id], $validated);

        return back()->with('success', 'Perfil comercial actualizado.');
    }
}
