<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\Exceptions\InvalidNettingLineException;
use App\Domains\BusinessPartners\Exceptions\UnbalancedOpenItemNettingException;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BpOpenItemReconciliation;
use App\Domains\BusinessPartners\Models\BpOpenItemReconciliationLine;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliación interna de partidas abiertas (estilo SAP B1, equivalente de
 * AccountReconciliationService pero para bp_open_items en vez de
 * journal_details): agrupa N partidas de UN MISMO socio cuyo saldo pendiente
 * neto es cero, dejándolas cerradas entre sí — nunca toca ningún asiento ya
 * contabilizado, solo cierra el hueco que queda en el seguimiento de cartera
 * cuando una de las dos "partidas" en realidad ya se había cancelado por
 * fuera de ApplyPaymentService (ej. un saldo inicial y un pago que ya estaba
 * contabilizado directo contra la misma cuenta de control).
 *
 * Si el neto no da exactamente cero, la diferencia real se cobra/paga con un
 * pago de verdad (OpenItemController::applyPayment(), ya existente) contra
 * la partida que quede — esta reconciliación nunca genera un asiento para
 * "cuadrar" una diferencia.
 */
class OpenItemNettingService
{
    /**
     * @param  int[]  $openItemIds
     */
    public function reconcile(array $openItemIds, ?int $reconciledBy = null): BpOpenItemReconciliation
    {
        $openItemIds = array_values(array_unique($openItemIds));

        if (count($openItemIds) < 2) {
            throw new InvalidNettingLineException('Una reconciliación necesita al menos 2 partidas.');
        }

        return DB::transaction(function () use ($openItemIds, $reconciledBy) {
            $items = BpOpenItem::whereIn('id', $openItemIds)
                ->with('originJournalDetail')
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($openItemIds)) {
                throw new InvalidNettingLineException('Alguna de las partidas indicadas no existe.');
            }

            $businessPartnerId = $items->first()->business_partner_id;

            foreach ($items as $item) {
                if ($item->business_partner_id !== $businessPartnerId) {
                    throw new InvalidNettingLineException('Todas las partidas deben ser del mismo socio de negocio: no se puede cancelar el saldo de un socio contra el de otro.');
                }

                if ($item->status === 'closed') {
                    throw new InvalidNettingLineException("La partida #{$item->id} ya está cerrada.");
                }

                if ($item->reconciliationLine()->exists()) {
                    throw new InvalidNettingLineException("La partida #{$item->id} ya forma parte de otra reconciliación.");
                }
            }

            $net = $items->reduce(
                fn (string $carry, BpOpenItem $item) => bcadd($carry, $item->signedBalance(), 2),
                '0.00'
            );

            if (bccomp($net, '0.00', 2) !== 0) {
                throw new UnbalancedOpenItemNettingException("Las partidas seleccionadas no netean a cero: diferencia de {$net}.");
            }

            $reconciliation = BpOpenItemReconciliation::create([
                'business_partner_id' => $businessPartnerId,
                'reconciled_by' => $reconciledBy,
                'reconciled_at' => now(),
            ]);

            foreach ($items as $item) {
                BpOpenItemReconciliationLine::create([
                    'bp_open_item_reconciliation_id' => $reconciliation->id,
                    'bp_open_item_id' => $item->id,
                ]);

                $item->update([
                    'applied_amount' => $item->original_amount,
                    'balance' => '0.00',
                    'status' => 'closed',
                ]);
            }

            return $reconciliation->load('lines.openItem');
        });
    }
}
