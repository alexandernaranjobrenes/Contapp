<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostAllocationRuleReportLine implements JsonSerializable
{
    public function __construct(
        public readonly int $costCenterId,
        public readonly string $costCenterCode,
        public readonly string $costCenterName,
        public readonly string $definedPercentage,
        public readonly string $actualAmount,
        public readonly ?string $actualPercentage,
        public readonly ?string $variancePercentagePoints,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'cost_center_id' => $this->costCenterId,
            'cost_center_code' => $this->costCenterCode,
            'cost_center_name' => $this->costCenterName,
            'defined_percentage' => $this->definedPercentage,
            'actual_amount' => $this->actualAmount,
            'actual_percentage' => $this->actualPercentage,
            'variance_percentage_points' => $this->variancePercentagePoints,
        ];
    }
}
