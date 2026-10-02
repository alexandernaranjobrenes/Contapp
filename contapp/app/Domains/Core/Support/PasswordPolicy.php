<?php

namespace App\Domains\Core\Support;

use Closure;

/**
 * Qué tiene que tener una contraseña de CONTAPP. Un solo lugar para las tres
 * pantallas donde se elige una: activar la licencia, crear un usuario y
 * «Olvidé mi contraseña».
 *
 * Cada requisito es «al menos N caracteres de esta clase», y la clase es una
 * expresión regular que entienden igual PHP (/u) y JavaScript (/u). Por eso
 * la lista viaja tal cual a la pantalla: la lista de requisitos que se va
 * tildando mientras se escribe (PasswordRequirements.vue) evalúa lo mismo que
 * valida el servidor, sin copiar las reglas en dos lenguajes.
 *
 * Los ocho caracteres son un piso: con cuatro letras, cuatro números y un
 * carácter especial, la contraseña más corta que cumple todo tiene nueve.
 *
 * Solo aplica al elegir una contraseña. Las que ya existen siguen sirviendo
 * para entrar; el login no valida la forma, solo que coincida.
 */
final class PasswordPolicy
{
    /**
     * El texto de cada requisito completa la frase «al menos …».
     *
     * @return list<array{key: string, label: string, pattern: string, min: int}>
     */
    public static function requirements(): array
    {
        return [
            ['key' => 'length', 'label' => '8 caracteres', 'pattern' => '[\s\S]', 'min' => 8],
            ['key' => 'letters', 'label' => '4 letras', 'pattern' => '\p{L}', 'min' => 4],
            ['key' => 'digits', 'label' => '4 números', 'pattern' => '[0-9]', 'min' => 4],
            ['key' => 'uppercase', 'label' => 'una mayúscula', 'pattern' => '\p{Lu}', 'min' => 1],
            ['key' => 'lowercase', 'label' => 'una minúscula', 'pattern' => '\p{Ll}', 'min' => 1],
            // Puntuación o símbolo: ! # $ % & * . - _ @ … Un espacio no cuenta.
            ['key' => 'special', 'label' => 'un carácter especial', 'pattern' => '[\p{P}\p{S}]', 'min' => 1],
        ];
    }

    /**
     * Los textos de los requisitos que la contraseña no cumple.
     *
     * @return list<string>
     */
    public static function missing(string $password): array
    {
        $missing = [];

        foreach (self::requirements() as $requirement) {
            if (preg_match_all('/'.$requirement['pattern'].'/u', $password) < $requirement['min']) {
                $missing[] = $requirement['label'];
            }
        }

        return $missing;
    }

    /**
     * La regla de validación: un solo error que nombra todo lo que falta.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $missing = self::missing(is_string($value) ? $value : '');

            if ($missing !== []) {
                $fail('A la contraseña le falta tener al menos: '.implode(', ', $missing).'.');
            }
        };
    }
}
