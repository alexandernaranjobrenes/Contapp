<?php

/*
 * Para backup.sh: escribe las credenciales de la base en un archivo de
 * opciones de MySQL (el --defaults-extra-file de mysqldump) e imprime el
 * nombre de la base. Las da Laravel, con el .env y la caché de configuración
 * tal como las usa la aplicación: leer el .env a mano no interpreta igual las
 * comillas ni los caracteres escapados.
 *
 *     php scripts/hostinger/mysql-credentials.php <archivo>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = config('database.connections.'.config('database.default'));
$quote = fn ($value) => '"'.addcslashes((string) $value, '\\"').'"';

file_put_contents($argv[1], implode("\n", [
    '[client]',
    'host='.$quote($connection['host']),
    'port='.$quote($connection['port']),
    'user='.$quote($connection['username']),
    'password='.$quote($connection['password']),
])."\n");

echo $connection['database'];
