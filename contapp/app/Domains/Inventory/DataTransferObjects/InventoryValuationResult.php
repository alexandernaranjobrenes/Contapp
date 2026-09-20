<?php

namespace App\Domains\Inventory\DataTransferObjects;

use JsonSerializable;

class InventoryValuationResult implements JsonSerializable
{
    /**
     * @param  array<int, InventoryValuationRow>  $rows
     */
    public function __construct(
        public readonly string $asOf,
        public readonly array $rows,
        public readonly string $totalValueLocal,
        public readonly string $totalValueForeign,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'rows' => $this->rows,
            'total_value_local' => $this->totalValueLocal,
            'total_value_foreign' => $this->totalValueForeign,
        ];
    }
}
