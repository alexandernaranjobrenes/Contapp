<?php

namespace App\Domains\Billing\Support;

/**
 * Provincias, cantones y distritos con los códigos del nodo Ubicacion (Nota 14
 * de los Anexos v4.4). Los datos salen de la tabla oficial de Hacienda, en
 * resources/hacienda/ubicacion/v4.4.php (ahí está de dónde y con qué hash).
 *
 * El XSD solo exige dos dígitos; que la combinación exista lo revisa Hacienda
 * al recibir el comprobante. Acá se revisa antes, al guardar los datos.
 */
class CostaRicaTerritory
{
    /** @var array<string, array{name: string, cantons: array<string, array{name: string, districts: array<string, string>}>}>|null */
    private static ?array $tree = null;

    /** @return array<string, array{name: string, cantons: array<string, array{name: string, districts: array<string, string>}>}> */
    public static function tree(): array
    {
        return self::$tree ??= require resource_path('hacienda/ubicacion/v4.4.php');
    }

    /** @return array<string, string> código de un dígito => nombre */
    public static function provinces(): array
    {
        return array_map(fn (array $province) => $province['name'], self::tree());
    }

    public static function exists(?string $province, ?string $canton = null, ?string $district = null): bool
    {
        $node = self::tree()[(string) $province] ?? null;

        if ($node === null) {
            return false;
        }

        if ($canton === null) {
            return true;
        }

        $cantonNode = $node['cantons'][$canton] ?? null;

        if ($cantonNode === null) {
            return false;
        }

        return $district === null || isset($cantonNode['districts'][$district]);
    }

    /**
     * Las reglas de provincia, cantón y distrito para un formulario: cada
     * código tiene que existir dentro del anterior. Con $required, los tres
     * son obligatorios (el emisor); si no, la ubicación es opcional pero, si
     * viene, viene completa y coherente (el receptor).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function rules(array $input, bool $required): array
    {
        $province = isset($input['province']) ? (string) $input['province'] : null;
        $canton = isset($input['canton']) ? (string) $input['canton'] : null;

        return [
            'province' => [$required ? 'required' : 'nullable', 'required_with:canton,district', 'in:'.implode(',', array_keys(self::tree()))],
            'canton' => [$required ? 'required' : 'nullable', 'required_with:district', 'regex:/^\d{2}$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($province) {
                    if (self::exists($province) && ! self::exists($province, (string) $value)) {
                        $fail('Ese cantón no es de '.self::provinces()[$province].'.');
                    }
                },
            ],
            'district' => [$required ? 'required' : 'nullable', 'regex:/^\d{2}$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($province, $canton) {
                    if (self::exists($province, $canton) && ! self::exists($province, $canton, (string) $value)) {
                        $fail('Ese distrito no es de '.self::tree()[$province]['cantons'][$canton]['name'].'.');
                    }
                },
            ],
        ];
    }

    /**
     * «Cartago, Cartago, San Nicolás». Sin nombre para un código que no está
     * en la tabla, para no mostrar uno inventado.
     *
     * @return list<string>
     */
    public static function names(?string $province, ?string $canton, ?string $district): array
    {
        $node = self::tree()[(string) $province] ?? null;
        $cantonNode = $node['cantons'][(string) $canton] ?? null;

        return array_values(array_filter([
            $node['name'] ?? null,
            $cantonNode['name'] ?? null,
            $cantonNode['districts'][(string) $district] ?? null,
        ]));
    }
}
