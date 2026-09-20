<?php

namespace App\Domains\Inventory\DataTransferObjects;

/**
 * Una línea de devolución al proveedor. Se identifica por la LÍNEA de la
 * recepción original y no por el artículo suelto: de ahí salen el precio al
 * que se compró y el tope de cuánto se puede devolver.
 *
 * `creditedUnitPrice` es lo que el proveedor acepta acreditar. Por defecto es
 * el precio original, pero se deja digitar porque un proveedor puede acreditar
 * menos (cargo por reposición) o más, y esa diferencia contra el costo real de
 * la mercancía que sale es justamente lo que va a diferencia de precio.
 */
class PurchaseReturnLineInput
{
    public readonly string $quantity;

    public readonly ?string $creditedUnitPrice;

    public function __construct(
        public readonly int $receiptLineId,
        int|float|string $quantity,
        int|float|string|null $creditedUnitPrice = null,
        public readonly ?string $description = null,
    ) {
        $this->quantity = number_format((float) $quantity, 6, '.', '');
        $this->creditedUnitPrice = $creditedUnitPrice === null
            ? null
            : number_format((float) $creditedUnitPrice, 6, '.', '');

        if (bccomp($this->quantity, '0.000000', 6) <= 0) {
            throw new \InvalidArgumentException('La cantidad a devolver debe ser mayor a cero.');
        }

        if ($this->creditedUnitPrice !== null && bccomp($this->creditedUnitPrice, '0.000000', 6) < 0) {
            throw new \InvalidArgumentException('El precio acreditado no puede ser negativo.');
        }
    }
}
