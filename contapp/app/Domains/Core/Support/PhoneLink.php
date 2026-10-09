<?php

namespace App\Domains\Core\Support;

/**
 * La dirección que lleva un QR para abrir en el teléfono: el escaneo de
 * documentos de Conti y el de códigos de barras de los artículos.
 *
 * En desarrollo, con la IP de la computadora en la red local (IP_SCANNER, en
 * conti.scanner.ip) en vez de «localhost», que el teléfono no puede abrir;
 * con el esquema y el puerto de APP_URL. En producción va vacía y se usa
 * APP_URL tal cual.
 */
final class PhoneLink
{
    public static function url(string $routeName, string $token): string
    {
        $path = route($routeName, $token, false);
        $ip = config('conti.scanner.ip');

        if (blank($ip)) {
            return rtrim((string) config('app.url'), '/').$path;
        }

        $app = parse_url((string) config('app.url'));

        return ($app['scheme'] ?? 'http').'://'.$ip.(isset($app['port']) ? ':'.$app['port'] : '').$path;
    }
}
