<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class TrialBalanceResult implements JsonSerializable
{
    /**
     * @param  TrialBalanceRow[]  $rows
     */
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly array $rows,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'rows' => $this->rows,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
        ];
    }
}
