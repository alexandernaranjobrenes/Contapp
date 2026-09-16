<?php

namespace App\Domains\Billing\DataTransferObjects;

use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Support\FiscalCatalogs;

/**
 * Un impuesto aplicado a una línea. El PORCENTAJE no se digita cuando es IVA:
 * lo fija el código de tarifa (Nota 8.1), porque es la norma la que los
 * empareja y una combinación inventada haría rechazar el comprobante.
 */
class SalesTaxInput
{
    public readonly string $ratePercentage;

    public readonly ?string $exoneratedPercentage;

    public function __construct(
        public readonly string $taxCode = '01',
        public readonly ?string $ivaRateCode = null,
        ?string $ratePercentage = null,
        public readonly ?string $exonerationDocumentType = null,
        public readonly ?string $exonerationDocumentNumber = null,
        public readonly ?string $exonerationArticle = null,
        public readonly ?string $exonerationClause = null,
        public readonly ?string $exonerationInstitution = null,
        public readonly ?string $exonerationDate = null,
        int|float|string|null $exoneratedPercentage = null,
    ) {
        if (! array_key_exists($this->taxCode, FiscalCatalogs::TAX_CODES)) {
            throw new InvalidSalesDocumentException("Código de impuesto desconocido: {$this->taxCode}.");
        }

        if ($this->ivaRateCode !== null) {
            $this->ratePercentage = FiscalCatalogs::ivaPercentage($this->ivaRateCode);
        } elseif ($ratePercentage !== null) {
            $this->ratePercentage = number_format((float) $ratePercentage, 2, '.', '');
        } else {
            throw new InvalidSalesDocumentException(
                "El impuesto {$this->taxCode} requiere un código de tarifa de IVA o un porcentaje explícito."
            );
        }

        $this->exoneratedPercentage = $exoneratedPercentage === null
            ? null
            : number_format((float) $exoneratedPercentage, 2, '.', '');

        if ($this->exoneratedPercentage !== null) {
            if (bccomp($this->exoneratedPercentage, '0.00', 2) < 0 || bccomp($this->exoneratedPercentage, '100.00', 2) > 0) {
                throw new InvalidSalesDocumentException('El porcentaje exonerado debe estar entre 0 y 100.');
            }

            // Una exoneración sin documento que la respalde no es exoneración:
            // Hacienda exige el sub-nodo completo o ninguno.
            if ($this->exonerationDocumentType === null || $this->exonerationDocumentNumber === null) {
                throw new InvalidSalesDocumentException(
                    'Una exoneración requiere el tipo y el número del documento que la autoriza.'
                );
            }

            if (! array_key_exists($this->exonerationDocumentType, FiscalCatalogs::EXONERATION_DOCUMENT_TYPES)) {
                throw new InvalidSalesDocumentException(
                    "Tipo de documento de exoneración desconocido: {$this->exonerationDocumentType}."
                );
            }

            if ($this->exonerationInstitution !== null
                && ! array_key_exists($this->exonerationInstitution, FiscalCatalogs::EXONERATION_INSTITUTIONS)) {
                throw new InvalidSalesDocumentException(
                    "Institución emisora de exoneración desconocida: {$this->exonerationInstitution}."
                );
            }
        }
    }

    public function isExonerated(): bool
    {
        return $this->exoneratedPercentage !== null
            && bccomp($this->exoneratedPercentage, '0.00', 2) > 0;
    }
}
