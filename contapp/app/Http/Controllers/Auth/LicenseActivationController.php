<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Única puerta de entrada para gente nueva: no hay registro público. Sin un
 * código de licencia válido emitido previamente por un platform admin
 * (LicenseController), esta pantalla no deja pasar a nadie.
 */
class LicenseActivationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Activate');
    }

    public function store(Request $request, LicenseActivationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $result = $service->activate(
                $validated['code'],
                [
                    'legal_name' => $validated['legal_name'],
                    'trade_name' => $validated['trade_name'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
                ],
                [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ],
            );
        } catch (InvalidLicenseException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        Auth::login($result['user']);
        $request->session()->regenerate();
        $request->session()->put('current_company_id', $result['company']->id);

        return redirect()->route('dashboard')->with('success', 'Compañía activada. ¡Bienvenido a CONTAPP!');
    }
}
