<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Core\Support\PasswordPolicy;
use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Exceptions\InvitationException;
use App\Domains\Licensing\Models\LicenseInvitation;
use App\Domains\Licensing\Services\LicenseInvitationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El enlace del correo de una licencia asignada desde el backoffice
 * (LicenseInvitationService): la persona la acepta con los datos de su
 * primera compañía y, si hace falta, eligiendo su contraseña.
 *
 * Público, sin el middleware de invitados: quien ya tiene cuenta puede
 * abrirlo con la sesión iniciada. Al aceptar queda con la sesión de la
 * cuenta dueña de la licencia, aunque antes hubiera otra.
 */
class LicenseInvitationAcceptanceController extends Controller
{
    public function show(string $token, LicenseInvitationService $service): Response
    {
        $invitation = $service->findByToken($token);

        return Inertia::render('Auth/AcceptLicense', [
            'token' => $token,
            // 'pending' | 'expired' | 'accepted' | 'invalid'
            'state' => $invitation?->status() ?? 'invalid',
            'invitation' => $invitation && ! $invitation->isAccepted() ? [
                'name' => $invitation->personName(),
                'email' => $invitation->email,
                'new_account' => $invitation->account_type === LicenseInvitation::NEW,
                'requires_password' => $invitation->requiresPassword(),
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'license' => [
                    'category' => $invitation->license->category?->name,
                    'max_companies' => $invitation->license->max_companies,
                    'expires_at' => $invitation->license->expires_at?->format('Y-m-d'),
                ],
            ] : null,
            'passwordRequirements' => PasswordPolicy::requirements(),
        ]);
    }

    public function accept(Request $request, string $token, LicenseInvitationService $service): RedirectResponse
    {
        $invitation = $service->findByToken($token);

        if ($invitation === null) {
            return back()->withErrors(['invitation' => 'Este enlace no es válido. Revisá que lo hayas copiado entero, o pedile al equipo de CONTAPP que te reenvíe el correo.']);
        }

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'password' => $invitation->requiresPassword()
                ? ['required', 'string', 'confirmed', PasswordPolicy::rule()]
                : ['nullable'],
        ], [], ['legal_name' => 'razón social']);

        try {
            $result = $service->accept($invitation, [
                'legal_name' => $validated['legal_name'],
                'trade_name' => $validated['trade_name'] ?? null,
                'tax_id' => $validated['tax_id'] ?? null,
            ], $invitation->requiresPassword() ? $validated['password'] : null);
        } catch (InvitationException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        } catch (InvalidLicenseException|AccountNotEligibleException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        // La sesión pasa a ser la de la cuenta que aceptó. El hash de su
        // contraseña lo guarda el middleware auth.session al terminar esta
        // request, así que no la cierra en la siguiente.
        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->put('current_company_id', $result['company']->id);

        return redirect()->route('dashboard')
            ->with('success', "Licencia activada: ya sos Superusuario de {$result['company']->legal_name}");
    }
}
