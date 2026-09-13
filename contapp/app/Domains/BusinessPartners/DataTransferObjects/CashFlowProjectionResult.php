<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class CashFlowProjectionResult implements JsonSerializable
{
    /**
     * @param  array<string, string>  $bucketLabels  clave de bucket => etiqueta, en orden de despliegue (ver DayBucketScheme::untilDueLabels) — compartido por collections y payments, ambos vienen del mismo corte de días.
     * @param  ProjectionCurrencyGroup[]  $collections
     * @param  ProjectionCurrencyGroup[]  $payments
     */
    public function __construct(
        public readonly string $asOf,
        public readonly array $bucketLabels,
        public readonly array $collections,
        public readonly array $payments,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'as_of' => $this->asOf,
            'bucket_labels' => $this->bucketLabels,
            'collections' => $this->collections,
            'payments' => $this->payments,
        ];
    }
}
