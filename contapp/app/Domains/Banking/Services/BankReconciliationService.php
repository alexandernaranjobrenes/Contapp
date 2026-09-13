<?php

namespace App\Domains\Banking\Services;

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Banking\Exceptions\UnbalancedReconciliationException;
use App\Domains\Banking\Exceptions\UnsupportedBankCurrencyException;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\Banking\Models\BankReconciliationLine;
use App\Domains\Banking\Models\BankStatementLine;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Reproduce el flujo de corte bancario del sistema legado: saldo del banco vs
 * saldo de libros, con las 4 partidas de conciliación clásicas. Fórmula
 * estándar de contabilidad (no la del legado, cuya aritmética en la captura
 * de pantalla es ambigua):
 *
 *   saldo de libros ajustado = book_balance + créditos de banco no reg. - débitos de banco no reg.
 *   saldo de banco ajustado  = bank_balance + depósitos no acreditados  - cheques no pagados
 *
 * Ambos deben coincidir para poder cerrar la conciliación.
 */
class BankReconciliationService
{
    public function open(
        BankAccount $bankAccount,
        \DateTimeInterface $cutoffDate,
        int|float|string $bankBalance,
        ?int $createdBy = null,
    ): BankReconciliation {
        $company = Company::withoutGlobalScope(CompanyScope::class)->findOrFail($bankAccount->company_id);
        $bucket = $this->currencyBucket($company, $bankAccount);

        $bookBalance = $this->ledgerBalance($company, $bankAccount->gl_account_id, $bucket, $cutoffDate);

        $reconciliation = BankReconciliation::create([
            'bank_account_id' => $bankAccount->id,
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'bank_balance' => number_format((float) $bankBalance, 2, '.', ''),
            'book_balance' => $bookBalance,
            'status' => 'in_progress',
            'created_by' => $createdBy,
        ]);

        // Un movimiento ya confirmado contra el banco en una conciliación
        // ANTERIOR de esta misma cuenta no debe volver a pedir confirmación
        // acá — bug real corregido 2026-08-27: antes, cada conciliación
        // nueva volvía a listar TODO el historial como sin confirmar, sin
        // importar que ya se hubiera conciliado hace meses, inflando
        // unrecorded_deposits/unpaid_checks con movimientos que ya estaban
        // resueltos. Un movimiento que sigue genuinamente pendiente (ej. un
        // cheque que todavía no cambia) SÍ debe seguir apareciendo — por eso
        // el filtro es "ya confirmado alguna vez", no "ya visto antes".
        $alreadyConfirmedDetailIds = BankReconciliationLine::where('matched_in_bank', true)
            ->whereHas('bankReconciliation', fn ($q) => $q->where('bank_account_id', $bankAccount->id))
            ->pluck('journal_detail_id');

        $details = JournalDetail::where('account_id', $bankAccount->gl_account_id)
            ->whereNotIn('id', $alreadyConfirmedDetailIds)
            ->whereHas('journalEntry', function ($q) use ($company, $cutoffDate) {
                $q->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->id)
                    ->where('status', 'posted')
                    ->whereDate('posting_date', '<=', $cutoffDate->format('Y-m-d'));
            })
            ->get();

        foreach ($details as $detail) {
            BankReconciliationLine::create([
                'bank_reconciliation_id' => $reconciliation->id,
                'journal_detail_id' => $detail->id,
                'matched_in_books' => true,
                'matched_in_bank' => false,
            ]);
        }

        $this->recalculate($reconciliation);

        return $reconciliation->fresh('lines');
    }

    /**
     * Alterna el check "visto en banco" de una línea (no solo lo prende):
     * si ya estaba marcada, la desmarca y suelta la línea de estado de cuenta
     * que tuviera enlazada — así el usuario puede corregir un check por error
     * antes de cerrar, sin tener que reabrir toda la conciliación.
     */
    public function confirmInBank(BankReconciliationLine $line, ?BankStatementLine $statementLine = null): BankReconciliationLine
    {
        if ($line->matched_in_bank) {
            if ($line->bank_statement_line_id) {
                BankStatementLine::whereKey($line->bank_statement_line_id)->update(['matched' => false]);
            }

            $line->update(['matched_in_bank' => false, 'bank_statement_line_id' => null]);
        } else {
            $line->update([
                'matched_in_bank' => true,
                'bank_statement_line_id' => $statementLine?->id,
            ]);

            if ($statementLine) {
                $statementLine->update(['matched' => true]);
            }
        }

        $this->recalculate($line->bankReconciliation);

        return $line->fresh();
    }

    /**
     * Reabre una conciliación cerrada para poder corregir un check o el
     * saldo de banco — no recalcula qué líneas le pertenecen (esas quedaron
     * fijas al abrirla con open()), así que es segura sin importar si después
     * se abrieron conciliaciones más nuevas para la misma cuenta.
     */
    public function reopen(BankReconciliation $reconciliation): BankReconciliation
    {
        $reconciliation->update(['status' => 'in_progress']);

        return $reconciliation->fresh();
    }

    public function recalculate(BankReconciliation $reconciliation): BankReconciliation
    {
        $bankAccount = BankAccount::withoutGlobalScope(CompanyScope::class)->findOrFail($reconciliation->bank_account_id);
        $company = Company::withoutGlobalScope(CompanyScope::class)->findOrFail($bankAccount->company_id);
        $bucket = $this->currencyBucket($company, $bankAccount);

        $unrecordedDeposits = '0.00';
        $unpaidChecks = '0.00';

        $outstandingLines = $reconciliation->lines()
            ->where('matched_in_books', true)
            ->where('matched_in_bank', false)
            ->with('journalDetail')
            ->get();

        foreach ($outstandingLines as $line) {
            $detail = $line->journalDetail;
            $net = bcsub((string) $detail->{"debit_{$bucket}"}, (string) $detail->{"credit_{$bucket}"}, 2);

            if (bccomp($net, '0.00', 2) > 0) {
                $unrecordedDeposits = bcadd($unrecordedDeposits, $net, 2);
            } else {
                $unpaidChecks = bcadd($unpaidChecks, bcmul($net, '-1', 2), 2);
            }
        }

        $unmatchedStatementLines = BankStatementLine::where('bank_account_id', $bankAccount->id)
            ->where('matched', false)
            ->get();

        $unrecordedBankCredits = '0.00';
        $unrecordedBankDebits = '0.00';

        foreach ($unmatchedStatementLines as $statementLine) {
            $amount = (string) $statementLine->amount;

            if (bccomp($amount, '0.00', 2) > 0) {
                $unrecordedBankCredits = bcadd($unrecordedBankCredits, $amount, 2);
            } else {
                $unrecordedBankDebits = bcadd($unrecordedBankDebits, bcmul($amount, '-1', 2), 2);
            }
        }

        $reconciliation->update([
            'unrecorded_deposits' => $unrecordedDeposits,
            'unpaid_checks' => $unpaidChecks,
            'unrecorded_bank_credits' => $unrecordedBankCredits,
            'unrecorded_bank_debits' => $unrecordedBankDebits,
        ]);

        return $reconciliation->fresh();
    }

    /**
     * Elimina la conciliación completa, no solo sus líneas de enlace: a
     * diferencia de una AccountReconciliation, acá también puede haber
     * líneas de estado de cuenta importadas (BankStatementLine) marcadas
     * como "matched" — hay que soltarlas primero para que vuelvan a quedar
     * disponibles en una conciliación futura, igual que hace confirmInBank()
     * al desmarcar un check. Nunca toca journal_details: las líneas de la
     * conciliación son solo enlaces de seguimiento (matched_in_books/bank),
     * el asiento contable en sí queda intacto.
     */
    public function delete(BankReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation) {
            $matchedStatementLineIds = $reconciliation->lines()
                ->whereNotNull('bank_statement_line_id')
                ->pluck('bank_statement_line_id');

            if ($matchedStatementLineIds->isNotEmpty()) {
                BankStatementLine::whereIn('id', $matchedStatementLineIds)->update(['matched' => false]);
            }

            $reconciliation->lines()->delete();
            $reconciliation->delete();
        });
    }

    public function close(BankReconciliation $reconciliation): BankReconciliation
    {
        $reconciliation = $this->recalculate($reconciliation);

        if (! $reconciliation->isBalanced()) {
            throw new UnbalancedReconciliationException(
                "La conciliación #{$reconciliation->id} no cuadra: libros ajustados {$reconciliation->adjustedBookBalance()} vs banco ajustado {$reconciliation->adjustedBankBalance()}."
            );
        }

        $reconciliation->update(['status' => 'completed']);

        return $reconciliation->fresh();
    }

    private function currencyBucket(Company $company, BankAccount $bankAccount): string
    {
        if ($bankAccount->currency_id === $company->local_currency_id) {
            return 'local';
        }

        if ($bankAccount->currency_id === $company->foreign_currency_id) {
            return 'foreign';
        }

        throw new UnsupportedBankCurrencyException(
            'La cuenta bancaria debe estar en la moneda local o la extranjera configurada en la compañía.'
        );
    }

    private function ledgerBalance(Company $company, int $accountId, string $bucket, \DateTimeInterface $cutoffDate): string
    {
        $details = JournalDetail::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($company, $cutoffDate) {
                $q->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->id)
                    ->where('status', 'posted')
                    ->whereDate('posting_date', '<=', $cutoffDate->format('Y-m-d'));
            })
            ->get();

        $balance = '0.00';

        foreach ($details as $detail) {
            $balance = bcadd($balance, (string) $detail->{"debit_{$bucket}"}, 2);
            $balance = bcsub($balance, (string) $detail->{"credit_{$bucket}"}, 2);
        }

        return $balance;
    }
}
