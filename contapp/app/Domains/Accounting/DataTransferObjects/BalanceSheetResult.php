<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class BalanceSheetResult implements JsonSerializable
{
    /**
     * @param  IncomeStatementLine[]  $assets
     * @param  IncomeStatementLine[]  $liabilities
     * @param  IncomeStatementLine[]  $equity
     */
    public function __construct(
        public readonly string $asOf,
        public readonly array $assets,
        public readonly string $assetsTotal,
        public readonly array $liabilities,
        public readonly string $liabilitiesTotal,
        public readonly array $equity,
        public readonly string $equityTotal,
        public readonly string $currentYearEarnings,
        public readonly string $totalEquityAndEarnings,
        public readonly string $totalLiabilitiesAndEquity,
        public readonly bool $isBalanced,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'assets' => $this->assets,
            'assets_total' => $this->assetsTotal,
            'liabilities' => $this->liabilities,
            'liabilities_total' => $this->liabilitiesTotal,
            'equity' => $this->equity,
            'equity_total' => $this->equityTotal,
            'current_year_earnings' => $this->currentYearEarnings,
            'total_equity_and_earnings' => $this->totalEquityAndEarnings,
            'total_liabilities_and_equity' => $this->totalLiabilitiesAndEquity,
            'is_balanced' => $this->isBalanced,
        ];
    }
}
