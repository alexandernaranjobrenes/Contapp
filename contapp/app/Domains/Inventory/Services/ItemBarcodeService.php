<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\Item;

/**
 * Los códigos de barras de los artículos (docs/decisiones.md 2026-10-08).
 *
 * - Un código de producto (EAN-13, EAN-8, UPC-A) termina en un dígito
 *   verificador; si no calza, casi siempre es un número mal tecleado. Eso lo
 *   avisa el formulario (Utils/barcode.js) sin impedir guardar.
 * - Solo caracteres que se pueden dibujar (ASCII imprimible): con tildes o
 *   ñ no hay código de barras que los lleve.
 * - Para lo que no trae código de fábrica, CONTAPP genera un EAN-13 interno
 *   con el prefijo 200: GS1 reserva 200–299 para uso dentro del negocio, así
 *   que nunca choca con el de un producto real. Sirve para las etiquetas y
 *   los lectores propios, no para venderle a otro comercio (eso pide un
 *   código GS1 comprado).
 * - Un código de barras es de un solo artículo en la compañía: el lector
 *   tiene que dar con uno solo.
 */
final class ItemBarcodeService
{
    public const INTERNAL_PREFIX = '200';

    /** El siguiente EAN-13 interno de la compañía, que ningún artículo tiene. */
    public function next(int $companyId): string
    {
        $taken = Item::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('barcode', 'like', self::INTERNAL_PREFIX.'%')
            ->pluck('barcode')
            ->flip();

        // El que sigue al último generado; si alguien tecleó uno de la serie,
        // se salta.
        $last = $taken->keys()
            ->filter(fn (string $code) => preg_match('/^'.self::INTERNAL_PREFIX.'\d{10}$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, 3, 9))
            ->max() ?? 0;

        $sequence = $last;
        do {
            $sequence++;
            $base = self::INTERNAL_PREFIX.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);
            $code = $base.self::checkDigit($base);
        } while ($taken->has($code));

        return $code;
    }

    /** El dígito verificador de un EAN-13, EAN-8 o UPC-A: los dígitos sin el último. */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        // Desde la derecha, el primero pesa 3, el siguiente 1, y así.
        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /** El artículo de la compañía que ya tiene ese código, si hay otro. */
    public function owner(int $companyId, string $barcode, ?int $exceptItemId = null): ?Item
    {
        return Item::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('barcode', $barcode)
            ->when($exceptItemId, fn ($query) => $query->whereKeyNot($exceptItemId))
            ->first(['id', 'code', 'name']);
    }
}
