<?php

namespace App\Domains\BusinessPartners\DataTransferObjects;

use JsonSerializable;

/**
 * Un documento (partida abierta) puntual detrás del total agregado de una
 * fila de AgingRow — el detalle que pide el usuario al expandir un socio en
 * pantalla ("número de documento/fecha/monto y otros más").
 */
class AgingDocumentLine implements JsonSerializable
{
    public function __construct(
        public readonly string $documentLabel,
        public readonly ?string $dueDate,
        public readonly string $originalAmount,
        public readonly string $balance,
        public readonly string $bucket,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'document_label' => $this->documentLabel,
            'due_date' => $this->dueDate,
            'original_amount' => $this->originalAmount,
            'balance' => $this->balance,
            'bucket' => $this->bucket,
        ];
    }
}
