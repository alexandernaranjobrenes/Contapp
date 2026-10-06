<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Models\ContiToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Los pases de la API de Conti.
 *
 * CONTAPP emite uno por cada mensaje que la persona le manda a Conti
 * (ContiChatController) y lo pasa al agente de n8n en la metadata del
 * mensaje; el agente lo devuelve en cada consulta. El navegador nunca lo ve.
 *
 * Dura lo que dura una respuesta del agente: si se filtrara, sirve minutos y
 * solo para lo que esa persona ya puede ver y hacer en esa compañía —cada
 * consulta vuelve a revisar su membresía, su licencia y sus permisos—.
 */
class ContiTokenService
{
    public const TTL_MINUTES = 15;

    private const PREFIX = 'conti_';

    public function issue(User $user, int $companyId, int $ttlMinutes = self::TTL_MINUTES): string
    {
        // Limpieza de paso: los pases vencidos de esta persona ya no sirven.
        ContiToken::where('user_id', $user->id)->where('expires_at', '<', now()->subDay())->delete();

        $plain = self::PREFIX.Str::random(48);

        ContiToken::create([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $plain;
    }

    /** El pase vigente que corresponde a ese texto, o null. */
    public function resolve(?string $plain): ?ContiToken
    {
        if ($plain === null || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = ContiToken::where('token_hash', hash('sha256', $plain))->first();

        if ($token === null || $token->isExpired()) {
            return null;
        }

        // Una marca de uso por minuto alcanza para saber que se usó.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $token;
    }

    /** Al cerrar sesión, sus pases dejan de servir. */
    public function revokeForUser(int $userId): void
    {
        ContiToken::where('user_id', $userId)->delete();
    }
}
