<?php

namespace App\Domains\Billing\DataTransferObjects;

use App\Domains\Billing\Exceptions\InvalidSalesOrderException;

/**
 * Una línea de orden de pedido: qué artículo, de qué almacén y cuánto se
 * aparta. El precio es informativo —la factura es la que manda— pero se
 * guarda porque es lo pactado con el cliente.
 */
class SalesOrderLineInput
{
    public readonly string $quantity;

    public readonly ?string $unitPrice;

    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        int|float|string $quantity,
        int|float|string|null $unitPrice = null,
        public readonly ?string $description = null,
    ) {
        $this->quantity = number_format((float) $quantity, 6, '.', '');
        $this->unitPrice = $unitPrice === null ? null : number_format((float) $unitPrice, 5, '.', '');

        if (bccomp($this->quantity, '0.000000', 6) <= 0) {
            throw new InvalidSalesOrderException('La cantidad de una línea de pedido debe ser mayor a cero.');
        }

        if ($this->unitPrice !== null && bccomp($this->unitPrice, '0.00000', 5) < 0) {
            throw new InvalidSalesOrderException('El precio de una línea de pedido no puede ser negativo.');
        }
    }
}
