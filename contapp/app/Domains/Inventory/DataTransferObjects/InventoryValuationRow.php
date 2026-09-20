<?php

namespace App\Domains\Inventory\DataTransferObjects;

use JsonSerializable;

class InventoryValuationRow implements JsonSerializable
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $itemCode,
        public readonly string $itemName,
        public readonly ?string $itemGroup,
        public readonly ?string $uom,
        public readonly int $warehouseId,
        public readonly string $warehouseCode,
        public readonly string $quantity,
        public readonly string $valueLocal,
        public readonly string $valueForeign,
        /** Derivado (valor / cantidad), nunca leído de items.avg_cost_local. */
        public readonly string $unitCostLocal,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'item_id' => $this->itemId,
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'item_group' => $this->itemGroup,
            'uom' => $this->uom,
            'warehouse_id' => $this->warehouseId,
            'warehouse_code' => $this->warehouseCode,
            'quantity' => $this->quantity,
            'value_local' => $this->valueLocal,
            'value_foreign' => $this->valueForeign,
            'unit_cost_local' => $this->unitCostLocal,
        ];
    }
}
