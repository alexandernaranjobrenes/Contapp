<?php

namespace App\Domains\Banking\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cómo le fue a la última sincronización automática con el BCCR (el comando
 * contapp:sync-bccr-rates, que corre solo cada día). Tipos de cambio la
 * muestra: si el token vence o el BCCR se cae, se ve ahí, y no recién
 * cuando a alguien le sale un tipo de cambio viejo.
 *
 * Una sola para todo CONTAPP: el tipo de cambio es el mismo para todas las
 * compañías.
 */
final class BccrSyncStatus
{
    private const KEY = 'bccr:last-auto-sync';

    public static function record(bool $ok, string $date, string $message): void
    {
        Cache::forever(self::KEY, [
            'ok' => $ok,
            'date' => $date,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }

    /** @return array{ok: bool, date: string, message: string, at: string}|null */
    public static function last(): ?array
    {
        return Cache::get(self::KEY);
    }
}
