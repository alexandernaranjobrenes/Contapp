<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Models\CommercialInteraction;
use App\Domains\Licensing\Models\CommercialProfile;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Solo store(): el historial de interacciones es append-only (CLAUDE.md
 * secc. 15, "se agregan interacciones, no se editan retroactivamente") — a
 * propósito no hay update()/destroy() acá ni rutas para ellos.
 */
class CommercialInteractionController extends Controller
{
    public function store(Request $request, int $license): RedirectResponse
    {
        $license = License::findOrFail($license);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(CommercialInteraction::TYPES))],
            'occurred_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:2000'],
        ]);

        $profile = CommercialProfile::firstOrCreate(['license_id' => $license->id]);

        $profile->interactions()->create([
            ...$validated,
            'author_id' => $request->user('propietario')->id,
        ]);

        return back()->with('success', 'Interacción registrada.');
    }
}
