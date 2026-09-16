<?php

namespace App\Domains\Inventory\DataTransferObjects;

/**
 * Una línea de traslado. A diferencia de StockLineInput no lleva costo: el
 * valor trasladado es el promedio vigente del artículo y no se digita —
 * dejarlo digitar permitiría "mover" mercancía cambiándole el valor de paso.
 */
class StockTransferLineInput
{
    public readonly string $quantity;

    public function __construct(
        public readonly int $itemId,
        public readonly int $fromWarehouseId,
        public readonly int $toWarehouseId,
        int|float|string $quantity,
        public readonly ?int $fromWarehouseBinId = null,
        public readonly ?int $toWarehouseBinId = null,
        public readonly ?string $description = null,
    ) {
        $this->quantity = number_format((float) $quantity, 6, '.', '');

        if (bccomp($this->quantity, '0.000000', 6) <= 0) {
            throw new \InvalidArgumentException('La cantidad a trasladar debe ser mayor a cero.');
        }
    }
}
