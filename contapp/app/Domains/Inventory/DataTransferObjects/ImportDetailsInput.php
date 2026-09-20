<?php

namespace App\Domains\Inventory\DataTransferObjects;

use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\InventoryDocument;

/**
 * Los datos con los que una importación se identifica en la vida real. Van
 * juntos en un objeto y no como cinco parámetros sueltos de post() porque son
 * una sola cosa: o la entrada es importación y los trae, o no lo es.
 */
class ImportDetailsInput
{
    public function __construct(
        public readonly ?string $customsDeclaration = null,
        public readonly ?string $customsOffice = null,
        public readonly ?string $transportDocument = null,
        public readonly ?string $originCountry = null,
        public readonly ?\DateTimeInterface $customsDate = null,
    ) {
        if ($this->customsOffice !== null
            && ! array_key_exists($this->customsOffice, InventoryDocument::CUSTOMS_OFFICES)) {
            throw new InvalidStockMovementException("Aduana desconocida: {$this->customsOffice}.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'is_import' => true,
            'customs_declaration' => $this->customsDeclaration,
            'customs_office' => $this->customsOffice,
            'transport_document' => $this->transportDocument,
            'origin_country' => $this->originCountry,
            'customs_date' => $this->customsDate?->format('Y-m-d'),
        ];
    }
}
