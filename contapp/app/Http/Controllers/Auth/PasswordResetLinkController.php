<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Core\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * «Olvidé mi contraseña», primer paso: pedir el enlace por correo.
 *
 * Solo para el plano operativo (guard 'web'). El Propietario no recupera su
 * contraseña por una pantalla pública: su cuenta se aprovisiona a mano
 * (CLAUDE.md secc. 11) y el broker 'users' ni siquiera conoce su tabla.
 *
 * ── La respuesta es la misma exista o no el correo ───────────────────────
 *
 * Decir «ese correo no está registrado» convertiría esta pantalla en una
 * forma de averiguar quién tiene cuenta en CONTAPP. Por eso responde igual
 * en todos los casos: correo desconocido, enlace enviado, o enlace pedido
 * hace menos de un minuto (el broker no manda otro tan seguido).
 *
 * Y tarda lo mismo: el envío va DESPUÉS de responder (defer). Hablar con el
 * servidor de correo toma uno o dos segundos, y esa demora delataría a los
 * correos que sí existen tan claramente como el mensaje.
 */
class PasswordResetLinkController extends Controller
{
    /** Pedidos por minuto desde una misma dirección IP. */
    private const MAX_ATTEMPTS = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'expiresInMinutes' => (int) config('auth.passwords.users.expire'),
            'resendAfterSeconds' => (int) config('auth.passwords.users.throttle'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $limiterKey = 'password-reset-link:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Esperá '.RateLimiter::availableIn($limiterKey).' segundos y volvé a probar.',
            ]);
        }

        RateLimiter::hit($limiterKey, 60);

        $email = $validated['email'];
        $ip = $request->ip();

        defer(function () use ($email, $ip) {
            $status = Password::broker('users')->sendResetLink(['email' => $email]);

            if ($status !== Password::RESET_LINK_SENT) {
                return;
            }

            $user = User::where('email', $email)->first();

            // Sin compañía: la contraseña es de la persona, que puede
            // pertenecer a compañías de varias licencias.
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'user.password_reset_requested',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'ip_address' => $ip,
                'created_at' => now(),
            ]);
        });

        return back();
    }
}
