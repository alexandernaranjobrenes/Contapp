<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class IncomeStatementLine implements JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly string $amount,
        public readonly int $depth = 0,
        public readonly bool $isHeader = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'description' => $this->description,
            'amount' => $this->amount,
            'depth' => $this->depth,
            'is_header' => $this->isHeader,
        ];
    }
}
