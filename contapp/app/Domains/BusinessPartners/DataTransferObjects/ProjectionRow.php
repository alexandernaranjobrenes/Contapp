<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class ProjectionRow implements JsonSerializable
{
    /**
     * @param  array<string, string>  $buckets  clave de bucket (ver DayBucketScheme::untilDueLabels) => monto
     * @param  ProjectionDocumentLine[]  $documents
     */
    public function __construct(
        public readonly string $partnerCode,
        public readonly string $partnerName,
        public readonly array $buckets,
        public readonly string $total,
        public readonly array $documents = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'partner_code' => $this->partnerCode,
            'partner_name' => $this->partnerName,
            'buckets' => $this->buckets,
            'total' => $this->total,
            'documents' => $this->documents,
        ];
    }
}
