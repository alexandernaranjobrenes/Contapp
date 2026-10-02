<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Core\Support\PasswordPolicy;
use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La puerta pública para canjear un código de licencia: no hay registro
 * público, y sin un código válido emitido por el Propietario (LicenseController)
 * esta pantalla no deja pasar a nadie.
 *
 * Dos casos, que elige quien llena el formulario:
 *   - `account=new` (o sin `account`): alguien nuevo. Se le crea la cuenta.
 *   - `account=existing`: alguien que ya tiene cuenta —Administrador o
 *     Usuario en la licencia de otra persona— y quiere la suya. Tiene que
 *     demostrar que la cuenta es suya con su contraseña actual; el formulario
 *     nunca le cambia el nombre ni la contraseña a una cuenta que ya existe.
 *
 * Con la sesión ya iniciada se entra por LicenseRedemptionController.
 */
class LicenseActivationController extends Controller
{
    /** Intentos fallidos de contraseña por minuto, por correo y dirección IP. */
    private const MAX_ATTEMPTS = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/Activate', [
            'passwordRequirements' => PasswordPolicy::requirements(),
        ]);
    }

    public function store(Request $request, LicenseActivationService $service): RedirectResponse
    {
        $existingAccount = $request->input('account') === 'existing';

        $validated = $request->validate([
            'account' => ['nullable', 'string', 'in:new,existing'],
            'code' => ['required', 'string'],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email'],
            ...($existingAccount ? [
                // La contraseña que ya tiene: se comprueba, no se le exige
                // la política ni se le pide dos veces.
                'password' => ['required', 'string'],
            ] : [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            ]),
        ]);

        $companyData = [
            'legal_name' => $validated['legal_name'],
            'trade_name' => $validated['trade_name'] ?? null,
            'tax_id' => $validated['tax_id'] ?? null,
        ];

        try {
            $result = $existingAccount
                ? $service->activateForExistingUser(
                    $validated['code'],
                    $companyData,
                    $this->accountOwner($request, $service, $validated),
                )
                : $service->activate($validated['code'], $companyData, [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);
        } catch (InvalidLicenseException $e) {
            return $this->backWith($request, 'code', $e->getMessage());
        } catch (AccountNotEligibleException $e) {
            return $this->backWith($request, 'email', $e->getMessage());
        }

        Auth::login($result['user']);
        $request->session()->regenerate();
        $request->session()->put('current_company_id', $result['company']->id);

        return redirect()->route('dashboard')->with('success', $existingAccount
            ? "Licencia activada: ya sos Superusuario de {$result['company']->legal_name}"
            : 'Compañía activada. ¡Bienvenido a CONTAPP!');
    }

    /**
     * La cuenta existente, una vez comprobado que quien llena el formulario
     * conoce su contraseña.
     *
     * El código se revisa primero: sin una licencia válida en la mano, este
     * formulario no sirve para probar contraseñas ni para averiguar qué
     * correos tienen cuenta.
     *
     * @param  array{code: string, email: string, password: string}  $validated
     *
     * @throws InvalidLicenseException
     * @throws ValidationException
     */
    private function accountOwner(Request $request, LicenseActivationService $service, array $validated): User
    {
        $service->redeemable($validated['code']);

        $limiterKey = 'license-activation:'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Esperá '.RateLimiter::availableIn($limiterKey).' segundos y volvé a probar.',
            ]);
        }

        $credentials = ['email' => $validated['email'], 'password' => $validated['password']];

        if (! Auth::guard('web')->validate($credentials)) {
            RateLimiter::hit($limiterKey, 60);

            // El mismo texto que el login: no dice si falló el correo o la contraseña.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con ningún registro.',
            ]);
        }

        RateLimiter::clear($limiterKey);

        return User::where('email', $validated['email'])->firstOrFail();
    }

    private function backWith(Request $request, string $field, string $message): RedirectResponse
    {
        return back()
            ->withErrors([$field => $message])
            ->withInput($request->except(['password', 'password_confirmation']));
    }
}
