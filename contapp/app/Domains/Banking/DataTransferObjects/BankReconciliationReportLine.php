<?php

namespace App\Domains\Banking\DataTransferObjects;

use JsonSerializable;

/**
 * Un renglón de BankReconciliationLine — mismo detalle que ya muestra
 * Banking/Reconciliations/Show.vue (columnas Cuenta/Descripción/Débito/
 * Crédito/Cta/Bco), acá aplanado para el reporte. "type" clasifica el
 * movimiento igual que BankReconciliationService::recalculate(): débito =
 * depósito, crédito = cheque; "matchedInBank" en false es justo lo que
 * compone unrecorded_deposits/unpaid_checks (pendiente de confirmar contra
 * el banco).
 */
class BankReconciliationReportLine implements JsonSerializable
{
    public function __construct(
        public readonly string $date,
        public readonly string $document,
        public readonly string $description,
        public readonly string $debit,
        public readonly string $credit,
        public readonly string $type,
        public readonly bool $matchedInBank,
        public readonly ?string $referenceDocument,
        public readonly ?string $referenceDocumentDate,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'document' => $this->document,
            'description' => $this->description,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'type' => $this->type,
            'matched_in_bank' => $this->matchedInBank,
            'reference_document' => $this->referenceDocument,
            'reference_document_date' => $this->referenceDocumentDate,
        ];
    }
}
