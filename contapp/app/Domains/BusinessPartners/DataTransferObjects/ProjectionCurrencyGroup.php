<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class ProjectionCurrencyGroup implements JsonSerializable
{
    /**
     * @param  ProjectionRow[]  $rows
     * @param  array<string, string>  $bucketTotals
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly array $rows,
        public readonly array $bucketTotals,
        public readonly string $total,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'currency_code' => $this->currencyCode,
            'rows' => $this->rows,
            'bucket_totals' => $this->bucketTotals,
            'total' => $this->total,
        ];
    }
}
