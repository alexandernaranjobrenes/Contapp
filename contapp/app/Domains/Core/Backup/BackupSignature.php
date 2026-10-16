<?php

namespace App\Domains\Core\Backup;

use Illuminate\Support\Str;

/**
 * La firma del respaldo: un HMAC con una clave que sale de la APP_KEY de esta
 * instalación. Solo se importa lo que esta instalación exportó, tal cual: un
 * archivo armado o editado a mano (asientos que nunca existieron, salarios
 * cambiados) no pasa, aunque se conozca la contraseña del archivo.
 *
 * Si cambia la APP_KEY, los respaldos de antes ya no se pueden importar.
 */
final class BackupSignature
{
    public static function sign(string $digest): string
    {
        return hash_hmac('sha256', $digest, self::key());
    }

    public static function verify(string $digest, string $signature): bool
    {
        return hash_equals(self::sign($digest), $signature);
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key');

        if (Str::startsWith($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        return hash_hmac('sha256', 'contapp-respaldo-v1', $appKey, true);
    }
}
