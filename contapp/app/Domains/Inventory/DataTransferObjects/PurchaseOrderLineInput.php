<?php

namespace App\Domains\Inventory\DataTransferObjects;

/**
 * Una línea de orden de compra. El costo es el PACTADO y es informativo: el
 * costo real al que entra la mercancía lo fija la recepción, igual que en la
 * orden de pedido el precio real lo fija la factura.
 */
class PurchaseOrderLineInput
{
    public readonly string $quantity;

    public readonly string $unitCostLocal;

    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        int|float|string $quantity,
        int|float|string $unitCostLocal = 0,
        public readonly ?string $description = null,
    ) {
        $this->quantity = number_format((float) $quantity, 6, '.', '');
        $this->unitCostLocal = number_format((float) $unitCostLocal, 6, '.', '');

        if (bccomp($this->quantity, '0.000000', 6) <= 0) {
            throw new \InvalidArgumentException('La cantidad a ordenar debe ser mayor a cero.');
        }

        if (bccomp($this->unitCostLocal, '0.000000', 6) < 0) {
            throw new \InvalidArgumentException('El costo pactado no puede ser negativo.');
        }
    }
}
