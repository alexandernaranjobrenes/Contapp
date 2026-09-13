<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

class AgingRow implements JsonSerializable
{
    /**
     * @param  array<string, string>  $buckets  clave de bucket (ver DayBucketScheme::overdueLabels) => monto
     * @param  AgingDocumentLine[]  $documents  detalle de las partidas abiertas que componen esta fila — lo que despliega la pantalla al expandir un socio.
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
