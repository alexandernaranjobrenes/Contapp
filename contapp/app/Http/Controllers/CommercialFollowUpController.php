<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Models\CommercialFollowUp;
use App\Domains\Licensing\Models\CommercialProfile;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommercialFollowUpController extends Controller
{
    public function store(Request $request, int $license): RedirectResponse
    {
        $license = License::findOrFail($license);

        $validated = $request->validate([
            'next_action_date' => ['required', 'date'],
            'action_type' => ['required', 'string', 'max:255'],
        ]);

        $profile = CommercialProfile::firstOrCreate(['license_id' => $license->id]);

        $profile->followUps()->create([...$validated, 'status' => 'pending']);

        return back()->with('success', 'Seguimiento agendado.');
    }

    public function complete(int $commercialFollowUp): RedirectResponse
    {
        $followUp = CommercialFollowUp::findOrFail($commercialFollowUp);
        $followUp->update(['status' => 'completed']);

        return back()->with('success', 'Seguimiento marcado como completado.');
    }
}
