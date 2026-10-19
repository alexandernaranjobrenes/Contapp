<?php

namespace App\Domains\Billing\Support;

use Carbon\CarbonImmutable;

/**
 * La hora de Hacienda. La base guarda en UTC (la zona de la aplicación), pero
 * la clave numérica y FechaEmision van con la hora de Costa Rica: a las 7 de
 * la noche del 17 en Costa Rica ya es 18 en UTC, y una clave armada con la
 * fecha UTC no concordaría con la fecha de emisión.
 *
 * Eloquent guarda un Carbon con el texto de SU zona, sin el desfase: por eso
 * lo que se guarda va siempre en UTC (now()), y se pasa a Costa Rica solo
 * para armar la clave y el XML.
 */
final class HaciendaClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function local(\DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::zone());
    }

    /** «2026-10-17T10:15:00-06:00», el dateTime que esperan los XSD. */
    public static function format(\DateTimeInterface $moment): string
    {
        return self::local($moment)->format('Y-m-d\TH:i:sP');
    }

    /**
     * Una fecha sin hora (la de un documento de exoneración, un documento de
     * referencia de papel): medianoche en Costa Rica, no en UTC.
     */
    public static function startOfLocalDay(string|\DateTimeInterface $date): CarbonImmutable
    {
        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $day.' 00:00:00', self::zone());
    }

    /** Lo que se recibe de un formulario («2026-10-01» o «2026-10-01T09:30»), en hora de Costa Rica, a UTC para guardarlo. */
    public static function parseLocal(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::zone())->setTimezone('UTC');
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(config('hacienda.timezone', 'America/Costa_Rica'));
    }
}
