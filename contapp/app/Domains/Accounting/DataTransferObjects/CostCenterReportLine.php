<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostCenterReportLine implements JsonSerializable
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountCode,
        public readonly string $accountDescription,
        public readonly string $debit,
        public readonly string $credit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_description' => $this->accountDescription,
            'debit' => $this->debit,
            'credit' => $this->credit,
        ];
    }
}
