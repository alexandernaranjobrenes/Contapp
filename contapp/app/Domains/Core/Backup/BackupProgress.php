<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Models\CompanyBackup;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cuántas filas lleva un respaldo que se revisa, se compara o se importa,
 * para que la pantalla lo muestre mientras tanto.
 *
 * Importar y comparar corren en una transacción: lo que se escribe por la
 * misma conexión no lo ve nadie hasta el final, así que el avance se quedaba
 * en cero y saltaba al total. Por eso va por una conexión aparte (una copia
 * de la de siempre), que lo confirma en el momento.
 *
 * En SQLite (las pruebas) no puede haber dos que escriban a la vez: va por la
 * misma conexión, y se ve al terminar.
 */
final class BackupProgress
{
    private const CONNECTION = 'backup_progress';

    /** Una escritura por segundo como mucho: el avance no frena la importación. */
    private const EVERY_SECONDS = 1.0;

    /** @return callable(int, bool=): void */
    public static function for(CompanyBackup $backup): callable
    {
        $connection = self::connection();
        $last = 0.0;

        return function (int $done, bool $final = false) use ($backup, $connection, &$last): void {
            $now = microtime(true);

            if (! $final && $now - $last < self::EVERY_SECONDS) {
                return;
            }

            $last = $now;

            try {
                DB::connection($connection)->table('company_backups')->where('id', $backup->id)->update(['progress' => $done, 'updated_at' => now()]);
            } catch (Throwable $e) {
                // El avance es para mirar: si no se puede anotar, se sigue.
                report($e);
            }
        };
    }

    private static function connection(): string
    {
        $default = (string) config('database.default');
        $config = (array) config("database.connections.{$default}");

        if (($config['driver'] ?? null) === 'sqlite') {
            return $default;
        }

        if (config('database.connections.'.self::CONNECTION) === null) {
            config(['database.connections.'.self::CONNECTION => $config]);
        }

        return self::CONNECTION;
    }
}
