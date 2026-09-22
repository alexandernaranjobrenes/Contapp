<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\PriceOverrideNotAuthorizedException;
use App\Domains\Core\Models\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifica las credenciales del administrador que libera un cambio de
 * precio en el mostrador.
 *
 * ── Es un endpoint de contraseña: se trata como tal ──────────────────────
 *
 * - **Se limita la tasa de intentos.** Sin esto, el formulario de emisión
 *   sería un oráculo para probar contraseñas de administrador a voluntad,
 *   desde una sesión de vendedor ya autenticada. El límite es por compañía
 *   y por usuario que intenta, no global, para que un vendedor torpe no
 *   deje sin facturar a toda la empresa.
 * - **El mensaje de error no distingue** entre "ese usuario no existe",
 *   "la contraseña no es", y "existe pero no es administrador". Las tres
 *   respuestas distintas le dirían a un vendedor curioso quiénes son
 *   administradores y si acertó el usuario.
 * - **La contraseña no se registra en ningún lado**, ni en el log ni en la
 *   tabla de autorizaciones: se compara contra el hash y se descarta.
 *
 * ── Por qué credenciales y no un PIN ─────────────────────────────────────
 *
 * Un PIN sería más cómodo en un mostrador, pero es un segundo secreto que
 * hay que administrar, rotar y revocar aparte — y que en la práctica se
 * comparte. La contraseña que ya existe tiene todo eso resuelto.
 */
class PriceOverrideAuthorizer
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    /**
     * @throws PriceOverrideNotAuthorizedException
     */
    public function verify(string $email, string $password, int $companyId, ?int $requestedBy): User
    {
        $key = 'price-override:'.$companyId.':'.($requestedBy ?? 'anon');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new PriceOverrideNotAuthorizedException(
                'Demasiados intentos de autorización fallidos. Esperá '.
                RateLimiter::availableIn($key).' segundos antes de volver a intentar.'
            );
        }

        $authorizer = User::where('email', $email)->first();

        if ($authorizer === null || ! Hash::check($password, $authorizer->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw new PriceOverrideNotAuthorizedException($this->genericFailure());
        }

        if (! $this->belongsToCompany($authorizer, $companyId)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw new PriceOverrideNotAuthorizedException($this->genericFailure());
        }

        if (! app(PriceOverrideGuard::class)->canAuthorize($authorizer, $companyId)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw new PriceOverrideNotAuthorizedException($this->genericFailure());
        }

        // Solo se limpia al acertar: un intento exitoso significa que quien
        // está al teclado sí tiene la credencial.
        RateLimiter::clear($key);

        return $authorizer;
    }

    /**
     * El superusuario de la licencia no tiene fila en user_roles —está un
     * nivel por encima—, así que se comprueba aparte.
     */
    private function belongsToCompany(User $user, int $companyId): bool
    {
        if ($user->isSuperAdmin($companyId)) {
            return true;
        }

        return UserRole::where('user_id', $user->id)->where('company_id', $companyId)->exists();
    }

    private function genericFailure(): string
    {
        return 'No se pudo autorizar el cambio de precio: verificá el usuario y la contraseña, '.
            'y que esa persona sea administradora de esta compañía.';
    }
}
