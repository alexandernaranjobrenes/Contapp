<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostAllocationRuleReportResult implements JsonSerializable
{
    /**
     * @param  CostAllocationRuleReportGroup[]  $groups
     */
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly array $groups,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'groups' => $this->groups,
        ];
    }
}
