<?php

namespace App\Domains\Conti\Support;

use JsonSerializable;

/**
 * Lo que Conti nunca ve: la última revisión de todo lo que devuelven sus herramientas.
 *
 * Cada consulta ya arma sus filas con una lista explícita de campos
 * (ContiResourceCatalog), así que un dato sensible no debería llegar hasta
 * acá. Esto es la red de abajo, sobre todo para los reportes, que se
 * reutilizan tal cual los arma la aplicación: un correo, un teléfono, una
 * contraseña o un número de identificación se quitan; una cuenta bancaria se
 * deja con sus últimos cuatro dígitos.
 */
class ContiRedactor
{
    /** Se quitan, en cualquier nivel. */
    private const REMOVE = [
        'email', 'correo', 'commercial_email', 'phone', 'telefono', 'password', 'contrasena', 'remember_token',
        'token', 'token_hash', 'password_hash', 'security_code', 'identification', 'identification_number',
        'identificacion', 'ccss_number', 'birth_date', 'fecha_nacimiento', 'address', 'address_details',
        'direccion', 'photo_path', 'photo_url', 'gender', 'nationality', 'age', 'logo_path',
    ];

    /** Se dejan con sus últimos cuatro caracteres. */
    private const MASK = ['bank_account', 'account_number', 'iban', 'cuenta_iban', 'numero_cuenta'];

    public static function clean(mixed $data): mixed
    {
        if ($data instanceof JsonSerializable || is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        if (! is_array($data)) {
            return $data;
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REMOVE, true)) {
                continue;
            }

            if (is_string($key) && in_array(strtolower($key), self::MASK, true) && is_scalar($value)) {
                $clean[$key] = self::mask((string) $value);

                continue;
            }

            $clean[$key] = self::clean($value);
        }

        return $clean;
    }

    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $digits = preg_replace('/\s+/', '', $value);

        return mb_strlen($digits) <= 4 ? '••••' : '••••'.mb_substr($digits, -4);
    }

    /** ¿La columna de un reporte es de las que no se muestran? */
    public static function isRemovedKey(string $key): bool
    {
        return in_array(strtolower($key), self::REMOVE, true);
    }
}
