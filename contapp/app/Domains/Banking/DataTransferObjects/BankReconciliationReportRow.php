<?php

namespace App\Domains\Banking\DataTransferObjects;

use JsonSerializable;

class BankReconciliationReportRow implements JsonSerializable
{
    /**
     * @param  BankReconciliationReportLine[]  $lines
     */
    public function __construct(
        public readonly int $id,
        public readonly string $cutoffDate,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly string $bankBalance,
        public readonly string $unrecordedDeposits,
        public readonly string $unpaidChecks,
        public readonly string $adjustedBankBalance,
        public readonly string $bookBalance,
        public readonly string $unrecordedBankCredits,
        public readonly string $unrecordedBankDebits,
        public readonly string $adjustedBookBalance,
        public readonly bool $isBalanced,
        public readonly ?string $createdByName,
        public readonly array $lines,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'cutoff_date' => $this->cutoffDate,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'bank_balance' => $this->bankBalance,
            'unrecorded_deposits' => $this->unrecordedDeposits,
            'unpaid_checks' => $this->unpaidChecks,
            'adjusted_bank_balance' => $this->adjustedBankBalance,
            'book_balance' => $this->bookBalance,
            'unrecorded_bank_credits' => $this->unrecordedBankCredits,
            'unrecorded_bank_debits' => $this->unrecordedBankDebits,
            'adjusted_book_balance' => $this->adjustedBookBalance,
            'is_balanced' => $this->isBalanced,
            'created_by_name' => $this->createdByName,
            'lines' => $this->lines,
        ];
    }
}
