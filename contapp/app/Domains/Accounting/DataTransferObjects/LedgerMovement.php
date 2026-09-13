<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class LedgerMovement implements JsonSerializable
{
    public function __construct(
        public readonly string $date,
        public readonly string $document,
        public readonly string $description,
        public readonly string $debit,
        public readonly string $credit,
        public readonly string $balance,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'document' => $this->document,
            'description' => $this->description,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'balance' => $this->balance,
        ];
    }
}
