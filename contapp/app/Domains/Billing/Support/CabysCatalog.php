<?php

namespace App\Domains\Billing\Support;

/**
 * El Catálogo de Bienes y Servicios (CAByS 2025) completo, del archivo oficial
 * del Banco Central (resources/hacienda/cabys/cabys-2025.php; ahí está de dónde
 * y con qué hash). Es lo que ofrece la lista de CAByS en la ficha del artículo
 * y en la línea de la factura, y contra lo que se revisa un código antes de
 * preguntarle a Hacienda.
 */
class CabysCatalog
{
    /** @var array{sections: list<string>, codes: array<string, array{0: string, 1: string}>}|null */
    private static ?array $data = null;

    /** @var array<string, string>|null descripción normalizada, para buscar */
    private static ?array $searchable = null;

    /** @return array{sections: list<string>, codes: array<string, array{0: string, 1: string}>} */
    private static function data(): array
    {
        return self::$data ??= require resource_path('hacienda/cabys/cabys-2025.php');
    }

    public static function exists(string $code): bool
    {
        return isset(self::data()['codes'][$code]);
    }

    /**
     * @return array{code: string, description: string, tax: string, is_service: bool, section: string}|null
     */
    public static function find(string $code): ?array
    {
        $row = self::data()['codes'][$code] ?? null;

        return $row === null ? null : self::entry($code, $row);
    }

    public static function count(): int
    {
        return count(self::data()['codes']);
    }

    /**
     * Los códigos en orden, filtrados por `$query`: sin texto, todos; con
     * dígitos, los que empiezan así; con palabras, los que tienen todas en la
     * descripción (sin importar mayúsculas ni tildes).
     *
     * @return array{total: int, results: list<array{code: string, description: string, tax: string, is_service: bool, section: string}>}
     */
    public static function search(string $query, int $offset = 0, int $limit = 100): array
    {
        $query = trim($query);
        $codes = self::data()['codes'];

        if ($query === '') {
            $matches = array_keys($codes);
        } elseif (preg_match('/^\d+$/', $query)) {
            $matches = array_values(array_filter(array_keys($codes), fn (string $code) => str_starts_with($code, $query)));
        } else {
            $words = array_filter(explode(' ', self::normalize($query)), fn (string $w) => $w !== '');
            $matches = [];

            foreach (self::searchable() as $code => $text) {
                foreach ($words as $word) {
                    if (! str_contains($text, $word)) {
                        continue 2;
                    }
                }
                $matches[] = $code;
            }
        }

        $page = array_slice($matches, max(0, $offset), max(1, min($limit, 200)));

        return [
            'total' => count($matches),
            'results' => array_map(fn (string $code) => self::entry($code, $codes[$code]), $page),
        ];
    }

    /** @param array{0: string, 1: string} $row */
    private static function entry(string $code, array $row): array
    {
        return [
            'code' => $code,
            'description' => $row[0],
            'tax' => $row[1],
            'is_service' => FiscalCatalogs::cabysIsService($code),
            'section' => self::data()['sections'][(int) $code[0]] ?? '',
        ];
    }

    /** @return array<string, string> */
    private static function searchable(): array
    {
        return self::$searchable ??= array_map(fn (array $row) => self::normalize($row[0]), self::data()['codes']);
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
