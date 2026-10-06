<?php

namespace App\Domains\Conti\Support;

use DateTimeInterface;

/**
 * Cómo le llegan los datos a Conti: estados en palabras, fechas como
 * AAAA-MM-DD y montos como texto con dos decimales (sin redondeos de punto
 * flotante en el camino).
 */
class ContiFormat
{
    private const STATUSES = [
        'draft' => 'preliminar',
        'posted' => 'contabilizado',
        'voided' => 'anulado',
        'reversed' => 'revertido',
        'open' => 'abierto',
        'partial' => 'parcial',
        'partially_received' => 'recibida en parte',
        'received' => 'recibida',
        'invoiced' => 'facturado',
        'closed' => 'cerrado',
        'blocked' => 'bloqueado',
        'cancelled' => 'cancelado',
        'canceled' => 'cancelado',
        'active' => 'activo',
        'inactive' => 'inactivo',
        'suspended' => 'suspendido',
        'terminated' => 'de baja',
        'pending' => 'pendiente',
        'approved' => 'aprobado',
        'applied' => 'aplicado',
        'calculated' => 'calculado',
        'settled' => 'liquidado',
        'issued' => 'emitido',
        'accepted' => 'aceptado',
        'rejected' => 'rechazado',
        'in_stock' => 'en existencia',
        'held' => 'retenido',
        'expired' => 'vencido',
        'allocated' => 'asignado',
        'in_progress' => 'en proceso',
    ];

    public static function status(?string $status, array $labels = []): ?string
    {
        if ($status === null) {
            return null;
        }

        return $labels[$status] ?? self::STATUSES[$status] ?? $status;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    public static function money(mixed $value): ?string
    {
        $numeric = self::numeric($value);

        return $numeric === null ? null : bcadd($numeric, '0', 2);
    }

    public static function number(mixed $value, int $scale = 4): ?string
    {
        $numeric = self::numeric($value);

        if ($numeric === null) {
            return null;
        }

        $formatted = bcadd($numeric, '0', $scale);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    private static function numeric(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return is_float($value) ? sprintf('%.6F', $value) : (string) $value;
    }

    public static function yesNo(mixed $value): string
    {
        return $value ? 'sí' : 'no';
    }

    /** «1-01-001 Caja general»: el código y el nombre juntos, o null. */
    public static function codeName(?object $model, string $nameAttribute = 'name'): ?string
    {
        if ($model === null) {
            return null;
        }

        return trim(($model->code ?? '').' '.($model->{$nameAttribute} ?? ''));
    }
}
