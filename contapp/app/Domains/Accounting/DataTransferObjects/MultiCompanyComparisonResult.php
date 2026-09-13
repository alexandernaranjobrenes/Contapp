<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class MultiCompanyComparisonResult implements JsonSerializable
{
    /**
     * @param  MultiCompanyComparisonRow[]  $rows
     */
    public function __construct(
        public readonly string $asOf,
        public readonly string $from,
        public readonly string $to,
        public readonly array $rows,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'from' => $this->from,
            'to' => $this->to,
            'rows' => $this->rows,
        ];
    }
}
