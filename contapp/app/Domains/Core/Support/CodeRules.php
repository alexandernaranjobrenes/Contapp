<?php

namespace App\Domains\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Los códigos de los registros (CLAUDE.md secc. 36, docs/decisiones.md
 * 2026-10-14): los documentos se numeran solos; los datos maestros se
 * decide al crearlos —Pivox sugiere el siguiente— y quedan fijos desde que
 * tienen movimientos, porque el código es la llave con que los reconocen las
 * personas, los reportes, las plantillas, Conti y los papeles ya impresos.
 */
final class CodeRules
{
    /**
     * El código que sigue a otro: «PRV-0012» → «PRV-0013», con el mismo
     * ancho. Null si no termina en número o si los 50 siguientes ya existen.
     */
    public static function next(?string $code, callable $taken): ?string
    {
        if ($code === null || ! preg_match('/^(.*?)(\d+)$/', $code, $match)) {
            return null;
        }

        $width = strlen($match[2]);
        $number = (int) $match[2];

        for ($try = 0; $try < 50; $try++) {
            $candidate = $match[1].str_pad((string) ++$number, $width, '0', STR_PAD_LEFT);

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * El siguiente al último que se creó en esa consulta; si todavía no hay
     * ninguno (o el último no termina en número), el primero de la serie:
     * «EMP-0001». Sin primero de la serie, null: el catálogo no tiene un
     * formato que seguir.
     */
    public static function following(Builder $query, ?string $first = null, string $column = 'code'): ?string
    {
        $last = (clone $query)->latest('id')->value($column);
        $taken = fn (string $code) => (clone $query)->where($column, $code)->exists();

        $next = self::next($last, $taken);

        if ($next !== null || $first === null) {
            return $next;
        }

        return $taken($first) ? self::next($first, $taken) : $first;
    }

    /**
     * Frena el cambio de un código que ya está fijo, con el motivo. Igual
     * que el resto de la validación: el error vuelve al campo «code».
     */
    public static function ensureUnchanged(?string $lockedBecause, string $current, ?string $requested, string $field = 'code'): void
    {
        if ($lockedBecause !== null && $requested !== null && $requested !== $current) {
            throw ValidationException::withMessages([
                $field => "El código no se puede cambiar: {$lockedBecause}.",
            ]);
        }
    }
}
