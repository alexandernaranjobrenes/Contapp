<?php

namespace App\Domains\Inventory\DataTransferObjects;

/**
 * Una línea de movimiento de stock, tal como la digita quien arma el
 * documento. Qué significa cada campo depende de la operación:
 *
 * - goods_receipt: $quantity es lo que ingresa y $unitCostLocal es obligatorio
 *   (el costo al que entra, en moneda local).
 * - goods_issue: $quantity es lo que sale; el costo lo resuelve el promedio
 *   del artículo, por eso $unitCostLocal se ignora si viene.
 * - count_adjustment: $quantity es la cantidad CONTADA (la nueva existencia
 *   del almacén), no la diferencia — el service calcula el delta. El costo
 *   también sale del promedio: un conteo reubica unidades que la empresa ya
 *   tenía, no cambia cuánto valen.
 */
class StockLineInput
{
    public readonly string $quantity;

    public readonly ?string $unitCostLocal;

    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        int|float|string $quantity,
        int|float|string|null $unitCostLocal = null,
        public readonly ?string $description = null,
        // Obligatoria si el almacén usa ubicaciones, prohibida si no
        // (lo valida PostStockMovementService contra warehouses.uses_bins).
        public readonly ?int $warehouseBinId = null,
        // Obligatorio si el artículo maneja lotes, prohibido si no (lo valida
        // ItemLotResolver contra items.tracks_lots). Último parámetro y con
        // default: los call-sites que no manejan lotes no cambian.
        public readonly ?int $itemLotId = null,
        /**
         * Una serie por unidad si el artículo las maneja, vacío si no (lo
         * valida ItemSerialResolver contra items.tracks_serials). Es un
         * array y no un id porque una línea de 10 unidades serializadas
         * mueve 10 series distintas, a diferencia del lote, que es uno solo
         * para toda la línea.
         *
         * @var string[]
         */
        public readonly array $serialNumbers = [],
    ) {
        $this->quantity = number_format((float) $quantity, 6, '.', '');
        $this->unitCostLocal = $unitCostLocal === null ? null : number_format((float) $unitCostLocal, 6, '.', '');

        if (bccomp($this->quantity, '0.000000', 6) < 0) {
            throw new \InvalidArgumentException('La cantidad de una línea de inventario no puede ser negativa.');
        }

        if ($this->unitCostLocal !== null && bccomp($this->unitCostLocal, '0.000000', 6) < 0) {
            throw new \InvalidArgumentException('El costo unitario de una línea de inventario no puede ser negativo.');
        }
    }
}
