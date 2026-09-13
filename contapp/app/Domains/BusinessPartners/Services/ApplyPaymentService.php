<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Exceptions\OpenItemAlreadyClosedException;
use App\Domains\BusinessPartners\Exceptions\OpenItemOverpaymentException;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BpPaymentApplication;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de escritura para aplicar pagos/cobros contra partidas abiertas
 * (bp_open_items). No contabiliza nada por sí mismo: el asiento del pago/cobro
 * (TRB, CKB, DEB...) ya debe existir, creado antes vía PostJournalService; este
 * service solo enlaza ese asiento con la(s) partida(s) que cancela.
 *
 * $exchangeRate es opcional y es responsabilidad de quien llama pasarlo
 * únicamente cuando la partida está en moneda extranjera: con eso se calcula
 * el diferencial cambiario REALIZADO (distinto del no realizado que calcula
 * FxRevaluationService en cierre de período, Fase 3). Este service no genera
 * el asiento contable de esa diferencia; solo la deja registrada para reporte.
 */
class ApplyPaymentService
{
    public function apply(
        BpOpenItem $openItem,
        JournalEntry $paymentJournalEntry,
        int|float|string $amount,
        \DateTimeInterface $appliedDate,
        ?string $exchangeRate = null,
        ?int $createdBy = null,
    ): BpPaymentApplication {
        return DB::transaction(function () use ($openItem, $paymentJournalEntry, $amount, $appliedDate, $exchangeRate, $createdBy) {
            $openItem = BpOpenItem::whereKey($openItem->id)->lockForUpdate()->firstOrFail();

            if ($openItem->status === 'closed') {
                throw new OpenItemAlreadyClosedException("La partida #{$openItem->id} ya está cerrada.");
            }

            $appliedAmount = number_format((float) $amount, 2, '.', '');

            if (bccomp($appliedAmount, '0.00', 2) <= 0) {
                throw new \InvalidArgumentException('El monto a aplicar debe ser mayor a cero.');
            }

            $balance = (string) $openItem->balance;

            if (bccomp($appliedAmount, $balance, 2) > 0) {
                throw new OpenItemOverpaymentException(
                    "El monto aplicado ({$appliedAmount}) excede el saldo pendiente ({$balance}) de la partida #{$openItem->id}."
                );
            }

            $realizedFxDifference = $this->realizedFxDifference($openItem, $appliedAmount, $exchangeRate);

            $application = BpPaymentApplication::create([
                'payment_journal_entry_id' => $paymentJournalEntry->id,
                'open_item_id' => $openItem->id,
                'applied_amount' => $appliedAmount,
                'applied_date' => $appliedDate->format('Y-m-d'),
                'exchange_rate' => $exchangeRate,
                'realized_fx_difference' => $realizedFxDifference,
                'created_by' => $createdBy,
            ]);

            $newApplied = bcadd((string) $openItem->applied_amount, $appliedAmount, 2);
            $newBalance = bcsub((string) $openItem->original_amount, $newApplied, 2);

            $openItem->update([
                'applied_amount' => $newApplied,
                'balance' => $newBalance,
                'status' => bccomp($newBalance, '0.00', 2) === 0 ? 'closed' : 'partial',
            ]);

            return $application;
        });
    }

    /**
     * La base de comparación NO siempre es el tipo de cambio original de la
     * línea que abrió la partida: si esta partida ya pasó por una
     * revaluación de cierre (FxRevaluationService, que reconoce el
     * diferencial NO realizado hasta esa fecha y deja last_revaluation_rate
     * en la partida), hay que partir de ESE tipo de cambio — si no, la
     * porción ya reconocida como no realizado se contaría otra vez acá como
     * realizado (docs/decisiones.md 2026-08-24).
     */
    private function realizedFxDifference(BpOpenItem $openItem, string $appliedAmount, ?string $exchangeRate): string
    {
        if ($exchangeRate === null) {
            return '0.00';
        }

        $originalRate = $openItem->last_revaluation_rate ?? $openItem->originJournalDetail?->exchange_rate_lc_fc;

        if (! $originalRate) {
            return '0.00';
        }

        $rateDifference = bcsub($exchangeRate, (string) $originalRate, 10);

        return number_format((float) bcmul($appliedAmount, $rateDifference, 10), 2, '.', '');
    }
}
