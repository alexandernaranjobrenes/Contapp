<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostAllocationRuleReportGroup implements JsonSerializable
{
    /**
     * @param  CostAllocationRuleReportLine[]  $lines
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly string $ruleCode,
        public readonly string $ruleName,
        public readonly array $lines,
        public readonly string $totalAmount,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_code' => $this->ruleCode,
            'rule_name' => $this->ruleName,
            'lines' => $this->lines,
            'total_amount' => $this->totalAmount,
        ];
    }
}
