<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostCenterReportGroup implements JsonSerializable
{
    /**
     * @param  CostCenterReportLine[]  $lines
     */
    public function __construct(
        public readonly int $costCenterId,
        public readonly string $costCenterCode,
        public readonly string $costCenterName,
        public readonly array $lines,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'cost_center_id' => $this->costCenterId,
            'cost_center_code' => $this->costCenterCode,
            'cost_center_name' => $this->costCenterName,
            'lines' => $this->lines,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
        ];
    }
}
