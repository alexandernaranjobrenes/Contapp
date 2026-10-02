<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Support\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Olvidé mi contraseña», segundo paso: elegir la contraseña nueva con el
 * enlace que llegó por correo.
 *
 * El token lo valida el broker de Laravel: guardado con hash, vence en
 * auth.passwords.users.expire minutos y se borra al usarse, así que cada
 * enlace sirve una sola vez. Pedir otro enlace invalida el anterior.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        $email = (string) $request->query('email');
        $broker = Password::broker('users');

        // Se comprueba al abrir el enlace, no recién al enviar el formulario:
        // nadie debería escribir dos veces una contraseña para enterarse
        // después de que el enlace había vencido.
        $user = $email !== '' ? $broker->getUser(['email' => $email]) : null;

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $email,
            'valid' => $user !== null && $broker->tokenExists($user, $token),
            'expiresInMinutes' => (int) config('auth.passwords.users.expire'),
            'passwordRequirements' => PasswordPolicy::requirements(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ]);

        $status = Password::broker('users')->reset(
            [...$credentials, 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) use ($request) {
                DB::transaction(function () use ($user, $password, $request) {
                    // Cambiar el hash de la contraseña cierra las sesiones
                    // que estuvieran abiertas con la anterior (middleware
                    // auth.session, ver bootstrap/app.php); el remember_token
                    // nuevo deja sin efecto los «Mantener la sesión iniciada».
                    // password_chosen_at: desde acá la contraseña es de la
                    // persona —la eligió con un enlace que llegó a su correo—
                    // y ya no de quien le creó la cuenta. Es lo que le
                    // permite activar una licencia propia
                    // (LicenseActivationService::assertCanOwnLicense).
                    $user->forceFill([
                        'password' => $password,
                        'password_chosen_at' => now(),
                        'remember_token' => Str::random(60),
                    ])->save();

                    AuditLog::create([
                        'user_id' => $user->id,
                        'action' => 'user.password_reset',
                        'auditable_type' => User::class,
                        'auditable_id' => $user->id,
                        'ip_address' => $request->ip(),
                        'created_at' => now(),
                    ]);
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Un solo mensaje para «enlace inválido» y «correo desconocido»:
            // distinguirlos diría qué correos tienen cuenta.
            throw ValidationException::withMessages([
                'token' => __('passwords.token'),
            ]);
        }

        // El enlace también se puede abrir con la sesión iniciada (lo pide
        // LicenseRedemptionController para quien todavía tiene la contraseña
        // que le puso otra persona). Esa sesión se abrió con la contraseña
        // anterior: se cierra acá, igual que las de los demás dispositivos,
        // para volver a entrar con la nueva.
        if (strcasecmp((string) $request->user('web')?->email, $credentials['email']) === 0) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->with('success', 'Tu contraseña quedó cambiada. Ingresá con la nueva.');
    }
}
