<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login del Propietario, guard 'propietario' — completamente separado del
 * login de cliente (App\Http\Controllers\Auth\AuthenticatedSessionController,
 * guard 'web'). Sin registro público: la cuenta se aprovisiona a mano.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Backoffice/Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('propietario')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'Las credenciales no coinciden con ningún registro.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('backoffice.licenses.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('propietario')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('backoffice.login');
    }
}
