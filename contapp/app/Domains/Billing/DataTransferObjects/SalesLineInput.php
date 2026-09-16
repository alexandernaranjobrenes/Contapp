<?php

namespace App\Domains\Billing\DataTransferObjects;

use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Support\FiscalCatalogs;

/**
 * Una línea del comprobante. Los datos fiscales del artículo (CAByS, unidad,
 * descripción) se reciben ya resueltos en vez de leerse del maestro acá: el
 * comprobante debe poder reconstruirse tal cual se envió aunque el artículo
 * cambie después.
 */
class SalesLineInput
{
    public readonly string $quantity;

    public readonly string $unitPrice;

    public readonly string $discountAmount;

    /** @var SalesTaxInput[] */
    public readonly array $taxes;

    public function __construct(
        public readonly string $cabysCode,
        public readonly string $description,
        public readonly string $unitCode,
        int|float|string $quantity,
        int|float|string $unitPrice,
        array $taxes = [],
        public readonly ?int $itemId = null,
        public readonly ?int $warehouseId = null,
        public readonly ?int $warehouseBinId = null,
        public readonly ?string $itemCode = null,
        public readonly bool $isService = false,
        public readonly ?string $discountCode = null,
        public readonly ?string $discountReason = null,
        int|float|string $discountAmount = 0,
        public readonly ?string $vinOrSerial = null,
    ) {
        $this->quantity = number_format((float) $quantity, 3, '.', '');
        $this->unitPrice = number_format((float) $unitPrice, 5, '.', '');
        $this->discountAmount = number_format((float) $discountAmount, 5, '.', '');

        foreach ($taxes as $tax) {
            if (! $tax instanceof SalesTaxInput) {
                throw new InvalidSalesDocumentException('Cada impuesto de una línea debe ser un SalesTaxInput.');
            }
        }

        $this->taxes = array_values($taxes);

        if (! ctype_digit($this->cabysCode) || strlen($this->cabysCode) !== 13) {
            throw new InvalidSalesDocumentException(
                "El código CAByS debe tener exactamente 13 dígitos; se recibió \"{$this->cabysCode}\"."
            );
        }

        $length = mb_strlen($this->description);

        if ($length < 3 || $length > 200) {
            throw new InvalidSalesDocumentException('La descripción de la línea debe tener entre 3 y 200 caracteres.');
        }

        if (! array_key_exists($this->unitCode, FiscalCatalogs::UNITS)) {
            throw new InvalidSalesDocumentException("Unidad de medida desconocida: {$this->unitCode}.");
        }

        if (bccomp($this->quantity, '0.000', 3) <= 0) {
            throw new InvalidSalesDocumentException('La cantidad de una línea debe ser mayor a cero.');
        }

        if (bccomp($this->unitPrice, '0.00000', 5) < 0) {
            throw new InvalidSalesDocumentException('El precio unitario no puede ser negativo.');
        }

        if (bccomp($this->discountAmount, '0.00000', 5) > 0) {
            if ($this->discountCode === null) {
                throw new InvalidSalesDocumentException('Un descuento requiere su código (Nota 20).');
            }

            if (! array_key_exists($this->discountCode, FiscalCatalogs::DISCOUNT_CODES)) {
                throw new InvalidSalesDocumentException("Código de descuento desconocido: {$this->discountCode}.");
            }

            // El código 99 es "Otros": sin explicar cuáles, no dice nada.
            if ($this->discountCode === '99' && ($this->discountReason === null || $this->discountReason === '')) {
                throw new InvalidSalesDocumentException('Un descuento con código 99 requiere explicar su naturaleza.');
            }

            if (bccomp($this->discountAmount, $this->totalAmount(), 5) > 0) {
                throw new InvalidSalesDocumentException('El descuento no puede superar el monto de la línea.');
            }
        }

        // Una línea de servicio no sale de una bodega; una de mercancía sí,
        // y sin eso no hay de dónde rebajar el stock ni qué costo llevar a
        // resultados.
        if (! $this->isService && $this->itemId !== null && $this->warehouseId === null) {
            throw new InvalidSalesDocumentException(
                "La línea del artículo {$this->itemCode} debe indicar de qué bodega sale."
            );
        }
    }

    public function totalAmount(): string
    {
        return $this->money(bcmul($this->quantity, $this->unitPrice, 10));
    }

    public function subtotal(): string
    {
        return bcsub($this->totalAmount(), $this->discountAmount, 5);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 5, '.', '');
    }
}
