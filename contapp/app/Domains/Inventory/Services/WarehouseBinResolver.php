<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use Illuminate\Support\Collection;

/**
 * Regla única de ubicaciones, compartida por el motor de movimientos y el de
 * traslados: una ubicación es obligatoria si el almacén las usa, y está
 * prohibida si no. Vive aparte para que las dos vías no puedan divergir —
 * aceptar una ubicación en un almacén que no las maneja dejaría existencia
 * registrada donde ningún reporte de ese almacén la va a mirar.
 */
class WarehouseBinResolver
{
    /**
     * @param  Collection<int, WarehouseBin>  $bins
     */
    public function resolve(Warehouse $warehouse, ?int $binId, Collection $bins): ?WarehouseBin
    {
        if (! $warehouse->uses_bins) {
            if ($binId !== null) {
                throw new InvalidStockMovementException(
                    "El almacén {$warehouse->code} no maneja ubicaciones; la línea no debe indicar una."
                );
            }

            return null;
        }

        if ($binId === null) {
            throw new InvalidStockMovementException(
                "El almacén {$warehouse->code} maneja ubicaciones; cada línea debe indicar en cuál."
            );
        }

        $bin = $bins->get($binId);

        if (! $bin || $bin->warehouse_id !== $warehouse->id) {
            throw new InvalidStockMovementException(
                "La ubicación id {$binId} no pertenece al almacén {$warehouse->code}."
            );
        }

        if ($bin->status !== 'active') {
            throw new InvalidStockMovementException("La ubicación {$bin->code} está inactiva; no admite movimientos.");
        }

        return $bin;
    }
}
