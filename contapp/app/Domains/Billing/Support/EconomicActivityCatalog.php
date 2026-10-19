<?php

namespace App\Domains\Billing\Support;

/**
 * Las actividades económicas de TRIBU-CR (CIIU 4), como las publicó Hacienda:
 * código de subclase («6209.0») => nombre. Los datos y su origen están en
 * resources/hacienda/actividades/ciiu4.php.
 *
 * Que una actividad esté en el catálogo no quiere decir que la compañía la
 * tenga inscrita: eso lo dice Hacienda por cédula (HaciendaPublicData::taxpayer),
 * y facturar con una no inscrita se rechaza (-408).
 */
class EconomicActivityCatalog
{
    /** @var array<string, string>|null */
    private static ?array $all = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$all ??= require resource_path('hacienda/actividades/ciiu4.php');
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    public static function name(string $code): ?string
    {
        return self::all()[$code] ?? null;
    }
}
