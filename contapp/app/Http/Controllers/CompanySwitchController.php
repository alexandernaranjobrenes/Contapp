<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cambiar la compañía activa (el selector de la barra superior, CLAUDE.md
 * secc. 30). Si la licencia de la compañía elegida está suspendida o
 * revocada, se rechaza acá con el motivo (error «license»), en vez de
 * guardarla y que SetCurrentCompany la descarte en la visita siguiente sin
 * decir por qué. Una licencia solo vencida sí deja entrar, en modo de gracia.
 */
class CompanySwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $belongs = $request->user()->companies()->whereKey($validated['company_id'])->wherePivot('status', 'active')->exists();

        abort_unless($belongs, 403, 'No pertenecés a esa compañía.');

        $company = Company::with('license')->findOrFail($validated['company_id']);
        $blocked = $company->license?->blockedMessage($company->trade_name ?: $company->legal_name);

        if ($blocked !== null) {
            return back()->withErrors(['license' => $blocked]);
        }

        $request->session()->put('current_company_id', $validated['company_id']);

        return back();
    }
}
