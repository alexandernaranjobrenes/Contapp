<?php

namespace App\Domains\Conti\Support;

use Illuminate\Support\Facades\Cache;

/**
 * El hilo de una conversación con Conti (CLAUDE.md secc. 32): los últimos
 * mensajes, solo texto, en la caché unas horas. Por persona, compañía y
 * conversación del chat. Nunca en la base.
 *
 * Lo escriben el chat (ContiChatController) y los formularios
 * (ContiFormController): así Conti sabe, en el mensaje siguiente, qué
 * formulario se envió y qué quedó preparado.
 */
final class ContiHistory
{
    public static function key(int $userId, int $companyId, string $session): string
    {
        return "conti:history:{$userId}:{$companyId}:{$session}";
    }

    /** @return list<array{role: string, content: string}> */
    public static function get(int $userId, int $companyId, string $session): array
    {
        $history = Cache::get(self::key($userId, $companyId, $session), []);

        return is_array($history) ? $history : [];
    }

    /** @param  array{role: string, content: string}  ...$messages */
    public static function append(int $userId, int $companyId, string $session, array ...$messages): void
    {
        $history = array_slice(
            [...self::get($userId, $companyId, $session), ...$messages],
            -max(2, (int) config('conti.history_messages', 16)),
        );

        Cache::put(self::key($userId, $companyId, $session), $history, now()->addMinutes((int) config('conti.history_minutes', 360)));
    }
}
