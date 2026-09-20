<?php

namespace App\Domains\Inventory\DataTransferObjects;

use JsonSerializable;

class InventoryAgingRow implements JsonSerializable
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $itemCode,
        public readonly string $itemName,
        public readonly ?string $itemGroup,
        public readonly int $warehouseId,
        public readonly string $warehouseCode,
        public readonly string $quantity,
        public readonly string $valueLocal,
        /** Fecha desde la que la existencia no rota: última salida, o primera entrada si nunca salió. */
        public readonly string $sinceDate,
        public readonly int $daysIdle,
        public readonly string $bucket,
        /** true si nunca tuvo una salida: la existencia está desde que entró. */
        public readonly bool $neverIssued,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'item_id' => $this->itemId,
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'item_group' => $this->itemGroup,
            'warehouse_id' => $this->warehouseId,
            'warehouse_code' => $this->warehouseCode,
            'quantity' => $this->quantity,
            'value_local' => $this->valueLocal,
            'since_date' => $this->sinceDate,
            'days_idle' => $this->daysIdle,
            'bucket' => $this->bucket,
            'never_issued' => $this->neverIssued,
        ];
    }
}
