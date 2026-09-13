<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\DataTransferObjects\BankReconciliationReportLine;
use App\Domains\Banking\DataTransferObjects\BankReconciliationReportRow;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\Banking\Models\BankReconciliationLine;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * Un renglón por conciliación (no por línea de detalle): el mismo nivel de
 * granularidad que ya muestra Banking/Reconciliations/Index.vue, con los
 * montos ajustados ya calculados — el usuario elige cuenta bancaria, año y
 * mes a mano (no hay detección automática de "períodos disponibles").
 */
class BankReconciliationReportService
{
    /**
     * @return BankReconciliationReportRow[]
     */
    public function build(BankAccount $bankAccount, int $year, int $month): array
    {
        return BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->whereYear('cutoff_date', $year)
            ->whereMonth('cutoff_date', $month)
            ->with([
                'createdBy:id,name',
                'lines.journalDetail:id,journal_entry_id,description,debit_local,credit_local,reference_document,reference_document_date',
                // JournalEntry sí lleva CompanyScope propio (a diferencia de
                // BankReconciliation/JournalDetail); acá el filtro real ya lo
                // dio bank_account_id -> $bankAccount (ya resuelto y scoped
                // por el caller), así que bypassearlo es seguro y necesario
                // para no depender de que haya un CurrentCompany ambiental
                // (ver mismo patrón en BankReconciliationService::open()).
                'lines.journalDetail.journalEntry' => function ($query) {
                    $query->withoutGlobalScope(CompanyScope::class)
                        ->select('id', 'document_type_id', 'document_number', 'posting_date');
                },
                // DocumentType también lleva CompanyScope propio — mismo
                // motivo que el bypass de arriba.
                'lines.journalDetail.journalEntry.documentType' => function ($query) {
                    $query->withoutGlobalScope(CompanyScope::class)->select('id', 'code');
                },
            ])
            ->orderBy('cutoff_date')
            ->get()
            ->map(fn (BankReconciliation $r) => new BankReconciliationReportRow(
                id: $r->id,
                cutoffDate: $r->cutoff_date->format('Y-m-d'),
                status: $r->status,
                statusLabel: $r->status === 'completed' ? 'Cerrada' : 'En proceso',
                bankBalance: (string) $r->bank_balance,
                unrecordedDeposits: (string) $r->unrecorded_deposits,
                unpaidChecks: (string) $r->unpaid_checks,
                adjustedBankBalance: $r->adjustedBankBalance(),
                bookBalance: (string) $r->book_balance,
                unrecordedBankCredits: (string) $r->unrecorded_bank_credits,
                unrecordedBankDebits: (string) $r->unrecorded_bank_debits,
                adjustedBookBalance: $r->adjustedBookBalance(),
                isBalanced: $r->isBalanced(),
                createdByName: $r->createdBy?->name,
                lines: $r->lines
                    ->sortBy(fn (BankReconciliationLine $l) => $l->journalDetail->journalEntry->posting_date)
                    ->map(fn (BankReconciliationLine $l) => new BankReconciliationReportLine(
                        date: $l->journalDetail->journalEntry->posting_date->format('Y-m-d'),
                        document: "{$l->journalDetail->journalEntry->documentType->code}-{$l->journalDetail->journalEntry->document_number}",
                        description: (string) $l->journalDetail->description,
                        debit: (string) $l->journalDetail->debit_local,
                        credit: (string) $l->journalDetail->credit_local,
                        type: bccomp((string) $l->journalDetail->debit_local, '0.00', 2) > 0 ? 'deposito' : 'cheque',
                        matchedInBank: $l->matched_in_bank,
                        referenceDocument: $l->journalDetail->reference_document,
                        referenceDocumentDate: $l->journalDetail->reference_document_date?->format('Y-m-d'),
                    ))
                    ->values()
                    ->all(),
            ))
            ->values()
            ->all();
    }
}
