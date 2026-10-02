<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Services\LicenseActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Canjear un código de licencia con la sesión ya iniciada: quien hoy es
 * Administrador o Usuario en la licencia de otra persona activa la suya con
 * la misma cuenta, y queda como Superusuario de su propia compañía sin dejar
 * de ser lo que era en las demás.
 *
 * Es el par autenticado de LicenseActivationController (la puerta pública),
 * igual que CompanyProvisioningController lo es para las compañías
 * adicionales. Lo puede usar cualquier rol: lo que se activa no es un permiso
 * dentro de la compañía activa, es una licencia propia. Por eso tampoco lo
 * frena el modo de gracia de la compañía en la que se esté parado (ver
 * EnforceLicenseGracePeriod).
 */
class LicenseRedemptionController extends Controller
{
    public function create(Request $request, LicenseActivationService $service): Response
    {
        $user = $request->user();
        $owned = $service->ownedLicense($user);

        return Inertia::render('Licenses/Redeem', [
            'account' => ['name' => $user->name, 'email' => $user->email],
            // Una cuenta, una licencia: si ya tiene, no hay nada que canjear.
            'ownedLicense' => $owned ? ['masked_code' => $owned->maskedCode()] : null,
            // Falso si la contraseña vigente la definió quien creó la cuenta:
            // antes de activar tiene que elegir una propia por correo.
            'passwordChosen' => $user->password_chosen_at !== null,
        ]);
    }

    public function store(Request $request, LicenseActivationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $result = $service->activateForExistingUser(
                $validated['code'],
                [
                    'legal_name' => $validated['legal_name'],
                    'trade_name' => $validated['trade_name'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
                ],
                $request->user(),
            );
        } catch (InvalidLicenseException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        } catch (AccountNotEligibleException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        // Queda parado en su compañía nueva.
        $request->session()->put('current_company_id', $result['company']->id);

        return redirect()->route('dashboard')
            ->with('success', "Licencia activada: ya sos Superusuario de {$result['company']->legal_name}");
    }

    /**
     * Manda a la propia casilla el enlace para elegir una contraseña: el
     * paso previo para quien tiene todavía la que le puso otra persona. El
     * enlace es el mismo de «Olvidé mi contraseña» (NewPasswordController).
     */
    public function sendPasswordLink(Request $request): RedirectResponse
    {
        $email = $request->user()->email;

        try {
            $status = Password::broker('users')->sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No pudimos enviar el correo. Probá de nuevo en unos minutos.');
        }

        return match ($status) {
            Password::RESET_LINK_SENT => back()->with('success', "Te enviamos a {$email} un enlace para elegir tu contraseña. Dura ".config('auth.passwords.users.expire').' minutos.'),
            Password::RESET_THROTTLED => back()->with('error', 'Ya te enviamos un enlace hace menos de un minuto. Revisá tu correo, también la carpeta de no deseado.'),
            default => back()->with('error', 'No pudimos enviar el correo. Probá de nuevo en unos minutos.'),
        };
    }
}
