<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Core\Exceptions\CompanyInvitationException;
use App\Domains\Core\Services\CompanyInvitationService;
use App\Domains\Core\Support\PasswordPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El enlace del correo de una invitación a una compañía
 * (CompanyInvitationService). Sin cuenta, la persona elige su nombre y su
 * contraseña; con cuenta, solo acepta.
 *
 * Público, sin el middleware de invitados: quien ya tiene cuenta puede
 * abrirlo con la sesión iniciada (tiene que ser la cuenta invitada). Al
 * aceptar queda con la sesión iniciada y parada en la compañía nueva: el
 * enlace llegó a su correo, que es lo mismo que pide recuperar la
 * contraseña.
 */
class CompanyInvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token, CompanyInvitationService $service): Response
    {
        $invitation = $service->findByToken($token);
        $account = $invitation ? $service->accountFor($invitation->email) : null;
        $sessionUser = $request->user('web');
        $company = $invitation?->company;

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            // 'pending' | 'expired' | 'accepted' | 'invalid'
            'state' => $invitation?->status() ?? 'invalid',
            'invitation' => $invitation && ! $invitation->isAccepted() ? [
                'email' => $invitation->email,
                'company' => $company->trade_name ?: $company->legal_name,
                'role' => $invitation->roleLabel(),
                'inviter' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'has_account' => $account !== null,
                'account_name' => $account?->name,
                // Con la sesión de otra cuenta abierta no se puede aceptar.
                'session_mismatch' => $sessionUser !== null && strcasecmp($sessionUser->email, $invitation->email) !== 0,
                'session_email' => $sessionUser?->email,
            ] : null,
            'passwordRequirements' => PasswordPolicy::requirements(),
        ]);
    }

    public function accept(Request $request, string $token, CompanyInvitationService $service): RedirectResponse
    {
        $invitation = $service->findByToken($token);

        if ($invitation === null) {
            return back()->withErrors(['invitation' => 'Este enlace no es válido. Revisá que lo hayas copiado entero, o pedile a quien te invitó que te lo vuelva a enviar.']);
        }

        $hasAccount = $service->accountFor($invitation->email) !== null;

        $validated = $hasAccount ? [] : $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ], [], ['name' => 'nombre']);

        try {
            $user = $service->accept($invitation, $request->user('web'), $validated);
        } catch (CompanyInvitationException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        // La sesión pasa a ser la de la cuenta que aceptó, parada en la
        // compañía de la invitación. El hash de su contraseña se guarda acá:
        // el middleware auth.session solo lo guarda si la request empezó con
        // alguien adentro, y un hash anterior que quedara en la sesión
        // cerraría esta en la siguiente visita.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->put('current_company_id', $invitation->company_id);

        $company = $invitation->company;

        return redirect()->route('dashboard')
            ->with('success', 'Ya tenés acceso a '.($company->trade_name ?: $company->legal_name)." como {$invitation->roleLabel()}.");
    }
}
