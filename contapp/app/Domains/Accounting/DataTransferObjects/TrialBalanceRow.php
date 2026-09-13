<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class TrialBalanceRow implements JsonSerializable
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $code,
        public readonly string $description,
        public readonly string $accountType,
        public readonly string $openingBalance,
        public readonly string $periodDebit,
        public readonly string $periodCredit,
        public readonly string $periodNet,
        public readonly string $closingBalance,
        public readonly int $depth = 0,
        public readonly bool $isHeader = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'account_id' => $this->accountId,
            'code' => $this->code,
            'description' => $this->description,
            'account_type' => $this->accountType,
            'opening_balance' => $this->openingBalance,
            'period_debit' => $this->periodDebit,
            'period_credit' => $this->periodCredit,
            'period_net' => $this->periodNet,
            'closing_balance' => $this->closingBalance,
            'depth' => $this->depth,
            'is_header' => $this->isHeader,
        ];
    }
}
