<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $belongs = $request->user()->companies()->whereKey($validated['company_id'])->wherePivot('status', 'active')->exists();

        abort_unless($belongs, 403, 'No pertenecés a esa compañía.');

        $request->session()->put('current_company_id', $validated['company_id']);

        return back();
    }
}
