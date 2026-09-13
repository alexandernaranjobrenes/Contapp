<?php

namespace App\Domains\Reporting\DataTransferObjects;

use Carbon\CarbonInterface;

class ReportHeader
{
    public function __construct(
        public readonly string $companyName,
        public readonly ?string $taxId,
        public readonly ?string $address,
        public readonly ?string $logoPath,
        public readonly string $title,
        public readonly string $paramsSummary,
        public readonly string $generatedByName,
        public readonly CarbonInterface $generatedAt,
    ) {}
}
