<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\BusinessPartners\Models\BpOpenItem;

/**
 * Abre una partida pendiente para una línea ya contabilizada que tiene socio
 * de negocio pero nunca marcó "abre partida" en su momento — el asiento
 * original queda intacto (nunca se toca cuenta, monto ni socio), solo se
 * crea el registro en bp_open_items que le faltaba, por el monto que esa
 * línea ya tiene contabilizado. Reutilizado por dos caminos: la corrección
 * manual de una línea puntual (JournalEntryController::updateLineDueDate) y
 * la reparación masiva de todas las líneas de la compañía con el mismo
 * problema (OpenItemBackfillService).
 */
class RetroactiveOpenItemService
{
    public function open(JournalDetail $detail, string $documentTypeCode, ?int $documentNumber, string $dueDate): BpOpenItem
    {
        $amount = bccomp($detail->debit_local, '0.00', 2) > 0 ? $detail->debit_local : $detail->credit_local;

        return BpOpenItem::create([
            'business_partner_id' => $detail->business_partner_id,
            'origin_journal_detail_id' => $detail->id,
            'document_type_code' => $documentTypeCode,
            'document_number' => $documentNumber,
            'due_date' => $dueDate,
            'original_amount' => $amount,
            'currency_id' => $detail->currency_id,
            'applied_amount' => '0.00',
            'balance' => $amount,
            'status' => 'open',
        ]);
    }
}
