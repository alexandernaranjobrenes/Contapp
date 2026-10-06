<?php

namespace App\Domains\Conti\Resources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Los filtros de uso común en las consultas de Conti. Aceptan lo que una
 * persona diría: un código en vez de un id, «sí» en vez de 1, el estado en
 * palabras («abierta») o con su clave («open»).
 */
final class ContiFilters
{
    /** Un registro relacionado, por su código. */
    public static function relatedCode(string $relation, string $column = 'code'): Closure
    {
        return fn (Builder $query, string $value) => $query->whereHas($relation, fn (Builder $related) => $related->where($column, $value));
    }

    /** Una columna, tal cual. */
    public static function equals(string $column): Closure
    {
        return fn (Builder $query, string $value) => $query->where($column, $value);
    }

    /** Sí o no. */
    public static function boolean(string $column): Closure
    {
        return fn (Builder $query, string $value) => $query->where($column, self::truthy($value));
    }

    /**
     * Un valor de una lista con etiqueta (estado, tipo, operación): por su
     * clave o por su etiqueta, sin importar mayúsculas ni tildes.
     *
     * @param  array<string, string>  $labels
     */
    public static function oneOf(string $column, array $labels): Closure
    {
        return function (Builder $query, string $value) use ($column, $labels) {
            $query->where($column, self::keyFor($value, $labels));
        };
    }

    /** @param  array<string, string>  $labels */
    public static function keyFor(string $value, array $labels): string
    {
        if (array_key_exists($value, $labels)) {
            return $value;
        }

        $wanted = Str::lower(Str::ascii(trim($value)));

        foreach ($labels as $key => $label) {
            $normalized = Str::lower(Str::ascii($label));

            if ($normalized === $wanted || Str::startsWith($normalized, $wanted)) {
                return (string) $key;
            }
        }

        return $value;
    }

    public static function truthy(string $value): bool
    {
        return in_array(Str::lower(Str::ascii(trim($value))), ['1', 'si', 'true', 'yes', 'verdadero', 's'], true);
    }

    /** «Lista: a, b, c», para describir qué acepta un filtro. */
    public static function options(array $labels): string
    {
        return implode(', ', array_map(fn ($key, $label) => "{$key} ({$label})", array_keys($labels), $labels));
    }
}
