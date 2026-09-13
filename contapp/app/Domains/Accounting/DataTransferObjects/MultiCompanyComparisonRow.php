<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class MultiCompanyComparisonRow implements JsonSerializable
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $currencyCode,
        public readonly string $assetsTotal,
        public readonly string $liabilitiesTotal,
        public readonly string $equityTotal,
        public readonly string $salesTotal,
        public readonly string $netProfit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'company_id' => $this->companyId,
            'company_name' => $this->companyName,
            'currency_code' => $this->currencyCode,
            'assets_total' => $this->assetsTotal,
            'liabilities_total' => $this->liabilitiesTotal,
            'equity_total' => $this->equityTotal,
            'sales_total' => $this->salesTotal,
            'net_profit' => $this->netProfit,
        ];
    }
}
