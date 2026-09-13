<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class AgingResult implements JsonSerializable
{
    /**
     * @param  array<string, string>  $bucketLabels  clave de bucket => etiqueta, en orden de despliegue (ver DayBucketScheme::overdueLabels) — mismas claves usadas en AgingRow::$buckets/AgingCurrencyGroup::$bucketTotals.
     * @param  AgingCurrencyGroup[]  $groups
     */
    public function __construct(
        public readonly string $asOf,
        public readonly string $partnerType,
        public readonly array $bucketLabels,
        public readonly array $groups,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'partner_type' => $this->partnerType,
            'bucket_labels' => $this->bucketLabels,
            'groups' => $this->groups,
        ];
    }
}
