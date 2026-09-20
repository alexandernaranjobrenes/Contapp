<?php

namespace App\Domains\Inventory\DataTransferObjects;

use JsonSerializable;

class InventoryAgingResult implements JsonSerializable
{
    /**
     * @param  array<int, InventoryAgingRow>  $rows
     * @param  array<string, string>  $bucketLabels  clave => etiqueta, en orden
     * @param  array<string, string>  $bucketTotals  clave => valor acumulado
     */
    public function __construct(
        public readonly string $asOf,
        public readonly array $rows,
        public readonly array $bucketLabels,
        public readonly array $bucketTotals,
        public readonly string $totalValueLocal,
        public readonly string $bucketsInput,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'rows' => $this->rows,
            'bucket_labels' => $this->bucketLabels,
            'bucket_totals' => $this->bucketTotals,
            'total_value_local' => $this->totalValueLocal,
            'buckets_input' => $this->bucketsInput,
        ];
    }
}
