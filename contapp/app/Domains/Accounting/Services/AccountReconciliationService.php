<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\InvalidReconciliationLineException;
use App\Domains\Accounting\Exceptions\UnbalancedAccountReconciliationException;
use App\Domains\Accounting\Models\AccountReconciliation;
use App\Domains\Accounting\Models\AccountReconciliationLine;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliación interna genérica (estilo SAP B1): agrupa movimientos
 * (journal_details) de UNA MISMA cuenta cuyo neto en moneda local es cero,
 * dejándolos marcados como "reconciliados" — no toca ningún monto ya
 * contabilizado, solo los enlaza. Ver AccountReconciliation para el porqué
 * de excluir líneas con socio de negocio.
 *
 * Desde 2026-09-07 admite reconciliación PARCIAL: cada línea de la
 * reconciliación indica cuánto del movimiento aplica (por defecto, todo lo
 * disponible), y lo que sobra queda libre para una reconciliación futura —
 * mismo principio que un pago parcial sobre una partida abierta de socio de
 * negocio (BpOpenItem/ApplyPaymentService), aplicado acá a movimientos sin
 * socio.
 */
class AccountReconciliationService
{
    /**
     * @param  array<int, array{journal_detail_id: int, amount?: string|float|int|null}>  $lines
     */
    public function reconcile(ChartOfAccount $account, array $lines, ?int $reconciledBy = null): AccountReconciliation
    {
        $byDetailId = [];
        foreach ($lines as $line) {
            $detailId = (int) ($line['journal_detail_id'] ?? $line['id'] ?? null);
            if ($detailId) {
                $byDetailId[$detailId] = $line['amount'] ?? null;
            }
        }

        if (count($byDetailId) < 2) {
            throw new \InvalidArgumentException('Una reconciliación necesita al menos 2 movimientos.');
        }

        return DB::transaction(function () use ($account, $byDetailId, $reconciledBy) {
            $details = JournalDetail::whereIn('id', array_keys($byDetailId))
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($details->count() !== count($byDetailId)) {
                throw new InvalidReconciliationLineException('Alguno de los movimientos indicados no existe o no está contabilizado.');
            }

            $reconciliation = AccountReconciliation::create([
                'account_id' => $account->id,
                'reconciled_by' => $reconciledBy,
                'reconciled_at' => now(),
            ]);

            $net = '0.00';

            foreach ($byDetailId as $detailId => $requestedAmount) {
                $detail = $details->get($detailId);

                if ($detail->account_id !== $account->id) {
                    throw new InvalidReconciliationLineException(
                        "El movimiento #{$detail->id} no pertenece a la cuenta {$account->code}: una reconciliación interna es siempre dentro de la misma cuenta."
                    );
                }

                if ($detail->business_partner_id !== null) {
                    throw new InvalidReconciliationLineException(
                        "El movimiento #{$detail->id} tiene socio de negocio asociado: se reconcilia por su partida abierta (Socios de negocio → Partidas abiertas), no acá."
                    );
                }

                $available = $detail->availableReconciliationAmount();
                $amount = $requestedAmount !== null ? number_format((float) $requestedAmount, 2, '.', '') : $available;

                if (bccomp($amount, '0.00', 2) <= 0) {
                    throw new InvalidReconciliationLineException("El monto a reconciliar del movimiento #{$detail->id} debe ser mayor a cero.");
                }

                if (bccomp($amount, $available, 2) > 0) {
                    throw new InvalidReconciliationLineException(
                        "El movimiento #{$detail->id} solo tiene {$available} disponible para reconciliar (ya se aplicó parte en otra reconciliación)."
                    );
                }

                $sign = bccomp(bcsub((string) $detail->debit_local, (string) $detail->credit_local, 2), '0.00', 2) < 0 ? '-1' : '1';

                $net = bcadd($net, bcmul($sign, $amount, 2), 2);

                AccountReconciliationLine::create([
                    'account_reconciliation_id' => $reconciliation->id,
                    'journal_detail_id' => $detail->id,
                    'amount' => $amount,
                ]);
            }

            if (bccomp($net, '0.00', 2) !== 0) {
                throw new UnbalancedAccountReconciliationException(
                    "Los montos seleccionados no cuadran: diferencia de {$net} en la cuenta {$account->code}."
                );
            }

            return $reconciliation->load('lines.journalDetail');
        });
    }

    /**
     * Deshacer una reconciliación no toca ningún asiento — solo borra el
     * enlace, así que es un delete físico normal (no una "reversión" en el
     * sentido de CLAUDE.md, que aplica a lo contabilizado, no a este marcado).
     * El saldo que esa reconciliación había consumido de cada movimiento
     * vuelve a quedar disponible automáticamente (se calcula on-the-fly).
     */
    public function unreconcile(AccountReconciliation $reconciliation): void
    {
        $reconciliation->delete();
    }

    /**
     * Atajo de un solo clic para "esto se digitó en la cuenta equivocada":
     * contabiliza el mismo traspaso ARR que expone
     * AccountReconciliationController::transfer() (débito/crédito inverso al
     * movimiento original, para cancelarlo exactamente) contra la cuenta o
     * socio correcto, y de una vez reconcilia dentro de $account el
     * movimiento original junto con la nueva contrapartida — el usuario
     * nunca ve el paso intermedio de reconciliación manual, solo elige el
     * destino correcto. Siempre usa el monto completo del movimiento
     * original (no tiene sentido reclasificar solo una parte y dejar el
     * resto mal ubicado).
     */
    public function reclassify(
        PostJournalService $postJournalService,
        Company $company,
        DocumentType $documentType,
        ChartOfAccount $account,
        JournalDetail $originalDetail,
        int $targetAccountId,
        ?int $targetBusinessPartnerId,
        \DateTimeInterface $postingDate,
        ?string $description,
        ?int $userId = null,
    ): AccountReconciliation {
        return DB::transaction(function () use (
            $postJournalService, $company, $documentType, $account, $originalDetail,
            $targetAccountId, $targetBusinessPartnerId, $postingDate, $description, $userId,
        ) {
            $available = $originalDetail->availableReconciliationAmount();

            if (bccomp($available, '0.00', 2) <= 0) {
                throw new InvalidReconciliationLineException("El movimiento #{$originalDetail->id} ya está completamente reconciliado.");
            }

            $net = bcsub((string) $originalDetail->debit_local, (string) $originalDetail->credit_local, 2);
            $isDebitOnAccount = bccomp($net, '0.00', 2) < 0;

            $entry = $postJournalService->post(
                $company, $documentType, $postingDate, $postingDate,
                [
                    new JournalLineInput(
                        $account->id, $originalDetail->currency_id,
                        debit: $isDebitOnAccount ? $available : 0,
                        credit: $isDebitOnAccount ? 0 : $available,
                    ),
                    new JournalLineInput(
                        $targetAccountId, $originalDetail->currency_id,
                        debit: $isDebitOnAccount ? 0 : $available,
                        credit: $isDebitOnAccount ? $available : 0,
                        businessPartnerId: $targetBusinessPartnerId,
                    ),
                ],
                $description ?: "Reclasificación — {$account->code}",
                $userId,
            );

            $newDetail = $entry->details->firstWhere('account_id', $account->id);

            return $this->reconcile($account, [
                ['journal_detail_id' => $originalDetail->id, 'amount' => $available],
                ['journal_detail_id' => $newDetail->id, 'amount' => $available],
            ], $userId);
        });
    }
}
