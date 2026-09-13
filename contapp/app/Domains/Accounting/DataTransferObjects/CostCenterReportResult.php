<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class CostCenterReportResult implements JsonSerializable
{
    /**
     * @param  CostCenterReportGroup[]  $groups
     */
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly array $groups,
        public readonly string $grandTotalDebit,
        public readonly string $grandTotalCredit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'groups' => $this->groups,
            'grand_total_debit' => $this->grandTotalDebit,
            'grand_total_credit' => $this->grandTotalCredit,
        ];
    }
}
