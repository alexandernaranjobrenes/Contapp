<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class PeriodComparisonLine implements JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly int $depth,
        public readonly bool $isHeader,
        public readonly PeriodComparisonTotal $amounts,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'description' => $this->description,
            'depth' => $this->depth,
            'is_header' => $this->isHeader,
            'amounts' => $this->amounts,
        ];
    }
}
