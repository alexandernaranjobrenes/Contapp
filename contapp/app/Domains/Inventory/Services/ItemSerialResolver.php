<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemSerial;
use Illuminate\Support\Collection;

/**
 * Regla única de números de serie, compartida por el motor de movimientos, el
 * de traslados y el de producción. Vive aparte por la misma razón que
 * ItemLotResolver y WarehouseBinResolver: si cada vía tuviera su copia, una
 * podría aceptar una serie que la otra rechaza y la trazabilidad quedaría
 * con huecos que nadie ve hasta que hay que atender una garantía.
 *
 * ── La regla central ─────────────────────────────────────────────────────
 *
 *     cantidad de series indicadas == cantidad de la línea
 *
 * No es una validación cosmética. Es lo que sostiene el invariante de esta
 * capa: el conteo de series en existencia de un artículo en un almacén tiene
 * que ser igual a su `item_warehouses.on_hand`. Si se pudieran recibir 10
 * unidades nombrando 8 series, el maestro y la existencia empezarían a
 * separarse desde el primer documento y nadie lo notaría hasta buscar una
 * serie que no está.
 *
 * Por lo mismo, un artículo serializado no admite cantidades fraccionarias:
 * media unidad no tiene número de serie.
 */
class ItemSerialResolver
{
    /**
     * Valida las series de una línea de ENTRADA y devuelve los números
     * normalizados.
     *
     * @param  string[]  $serialNumbers
     * @return string[]
     */
    public function resolveForReceipt(Item $item, array $serialNumbers, string $quantity): array
    {
        $serials = $this->normalize($item, $serialNumbers, $quantity);

        if ($serials === []) {
            return [];
        }

        // Una serie que ya está en existencia no puede volver a entrar: o es
        // un número tecleado mal, o es la misma unidad contada dos veces. Las
        // dos terminan en un inventario que dice tener algo que no tiene.
        $existing = ItemSerial::where('item_id', $item->id)
            ->whereIn('serial_number', $serials)
            ->inStock()
            ->pluck('serial_number');

        if ($existing->isNotEmpty()) {
            throw new InvalidStockMovementException(
                "Estas series de {$item->code} ya están en existencia y no pueden volver a entrar: ".
                $existing->implode(', ').'.'
            );
        }

        return $serials;
    }

    /**
     * Valida las series de una línea de SALIDA y devuelve los registros, ya
     * comprobado que están donde la línea dice.
     *
     * @param  string[]  $serialNumbers
     * @return Collection<int, ItemSerial>
     */
    public function resolveForIssue(
        Item $item,
        array $serialNumbers,
        string $quantity,
        int $warehouseId,
        ?int $binId,
    ): Collection {
        $serials = $this->normalize($item, $serialNumbers, $quantity);

        if ($serials === []) {
            return collect();
        }

        $found = ItemSerial::where('item_id', $item->id)
            ->whereIn('serial_number', $serials)
            ->get()
            ->keyBy('serial_number');

        $missing = array_diff($serials, $found->keys()->all());

        if ($missing !== []) {
            throw new InvalidStockMovementException(
                "Estas series no existen en el maestro de {$item->code}: ".implode(', ', $missing).'.'
            );
        }

        foreach ($found as $serial) {
            if ($serial->status !== 'in_stock') {
                throw new InvalidStockMovementException(
                    "La serie {$serial->serial_number} de {$item->code} ya no está en existencia ".
                    '('.(ItemSerial::STATUSES[$serial->status] ?? $serial->status).'); no puede salir dos veces.'
                );
            }

            // Sacarla del almacén donde no está sería mover existencia que
            // ese almacén no tiene, aunque el total del artículo cuadre.
            if ($serial->warehouse_id !== $warehouseId) {
                throw new InvalidStockMovementException(
                    "La serie {$serial->serial_number} de {$item->code} no está en el almacén de la línea."
                );
            }

            if ($binId !== null && $serial->warehouse_bin_id !== $binId) {
                throw new InvalidStockMovementException(
                    "La serie {$serial->serial_number} de {$item->code} no está en la ubicación de la línea."
                );
            }
        }

        return $found->values();
    }

    /**
     * Lo común a entrada y salida: que el artículo las maneje o no, que no
     * vengan repetidas y que la cuenta cuadre con la cantidad.
     *
     * @param  string[]  $serialNumbers
     * @return string[]
     */
    private function normalize(Item $item, array $serialNumbers, string $quantity): array
    {
        $serials = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $serialNumbers,
        ), fn (string $value) => $value !== ''));

        if (! $item->tracks_serials) {
            if ($serials !== []) {
                throw new InvalidStockMovementException(
                    "El artículo {$item->code} no maneja números de serie; la línea no debe indicarlos."
                );
            }

            return [];
        }

        // Media unidad no tiene número de serie.
        if (bccomp($quantity, (string) (int) (float) $quantity, 6) !== 0) {
            throw new InvalidStockMovementException(
                "El artículo {$item->code} maneja números de serie; su cantidad tiene que ser entera."
            );
        }

        $duplicates = array_diff_assoc($serials, array_unique($serials));

        if ($duplicates !== []) {
            throw new InvalidStockMovementException(
                "Estas series vienen repetidas en la línea de {$item->code}: ".
                implode(', ', array_unique($duplicates)).'.'
            );
        }

        $expected = (int) (float) $quantity;

        if (count($serials) !== $expected) {
            throw new InvalidStockMovementException(
                "El artículo {$item->code} maneja números de serie: la línea mueve {$expected} unidad(es) ".
                'y trae '.count($serials).'. Tiene que haber exactamente una serie por unidad.'
            );
        }

        return $serials;
    }
}
