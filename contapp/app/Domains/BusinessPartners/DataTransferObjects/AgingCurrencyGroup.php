<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class AgingCurrencyGroup implements JsonSerializable
{
    /**
     * @param  AgingRow[]  $rows
     * @param  array<string, string>  $bucketTotals  clave de bucket => monto total del grupo
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
