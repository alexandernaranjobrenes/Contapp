<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\DraftEntriesExistException;
use App\Domains\Accounting\Exceptions\InvalidPeriodTransitionException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\PeriodClosingProcess;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cierre/bloqueo/reapertura de períodos. La AUTORIZACIÓN de quién puede
 * reabrir un período Cerrado (solo super usuario, no delegable, según
 * docs/propuesta-contapp.md §4.4) es responsabilidad de la Policy que llame
 * a este service — igual patrón que JournalEntryPolicy con el borrado de
 * asientos: el service ejecuta el mecanismo, la Policy decide quién puede pedirlo.
 */
class PeriodCloseService
{
    public function __construct(private readonly PostJournalService $postJournalService)
    {
    }

    /**
     * Crea el año fiscal siguiente al más reciente que ya exista para la
     * compañía (o el año calendario actual, si todavía no tiene ninguno —
     * mismo caso base que DemoCompanySeeder), con sus 12 períodos mensuales
     * en estado 'open'. A propósito NO exige que el año anterior esté
     * cerrado: un negocio real necesita poder seguir contabilizando enero
     * aunque diciembre del año pasado todavía no esté auditado/cerrado —
     * decisión explícita del pedido, no un descuido.
     */
    public function createNextYear(Company $company): FiscalYear
    {
        return DB::transaction(function () use ($company) {
            // withoutGlobalScope: mismo criterio que el resto de este
            // archivo (ver buildClosingLines()) — este service no debe
            // depender de que CurrentCompany esté seteado en el contexto
            // ambiental para ser correcto.
            $lastYear = FiscalYear::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->max('year');
            $year = $lastYear ? $lastYear + 1 : (int) now()->format('Y');

            $fiscalYear = FiscalYear::create([
                'company_id' => $company->id,
                'year' => $year,
                'status' => 'open',
            ]);

            for ($month = 1; $month <= 12; $month++) {
                $start = Carbon::create($year, $month, 1)->startOfMonth();

                FiscalPeriod::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'period_number' => $month,
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $start->copy()->endOfMonth()->format('Y-m-d'),
                    'status' => 'open',
                ]);
            }

            return $fiscalYear->load('periods');
        });
    }

    /**
     * El tipo de documento reservado para el asiento de cierre anual: uno
     * por compañía, creado la primera vez que hace falta (no vía
     * seeder/migración — "creado de oficio por el sistema" cuando el
     * usuario lo usa, mismo criterio ya usado para el tipo APE de saldos
     * iniciales, ver OpeningBalanceBulkImporter::openingDocumentType()).
     * is_closing_type=true lo excluye del formulario manual de asientos
     * (JournalEntryController::formProps()) y del mayor auxiliar de cuentas
     * de resultados (LedgerService::build()) — no debe verse mezclado con
     * la actividad real del año que justamente viene a cancelar.
     */
    public function closingDocumentType(Company $company): DocumentType
    {
        // withoutGlobalScope: DocumentType también trae CompanyScope
        // automático — sin este bypass, firstOrCreate() nunca "encontraría"
        // el ACC ya creado sin CurrentCompany ambiental, e intentaría
        // insertarlo de nuevo cada vez, chocando con el índice único.
        return DocumentType::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACC'],
            [
                'name' => 'Asiento de cierre contable',
                'origin_module' => 'contable',
                'generates_journal' => true,
                'currency_mode' => 'local_fija',
                'is_closing_type' => true,
                'status' => 'active',
            ],
        );
    }

    public function block(FiscalPeriod $period): FiscalPeriod
    {
        return $this->transition($period, 'open', 'blocked');
    }

    public function unblock(FiscalPeriod $period): FiscalPeriod
    {
        return $this->transition($period, 'blocked', 'open');
    }

    public function close(Company $company, FiscalPeriod $period, ?int $executedBy = null): FiscalPeriod
    {
        if (! in_array($period->status, ['open', 'blocked'], true)) {
            throw new InvalidPeriodTransitionException("El período #{$period->period_number} ya está '{$period->status}'.");
        }

        // Un borrador nunca tiene fiscal_period_id asignado (eso se resuelve
        // recién al contabilizar formalmente, ver PostJournalService::saveDraft) —
        // así que la única forma de saber si "cae" dentro de este período es
        // por su posting_date (la fecha rectora, ver docs/decisiones.md
        // 2026-08-22), no por fiscal_period_id ni por document_date.
        $hasDrafts = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'draft')
            ->whereDate('posting_date', '>=', $period->start_date->format('Y-m-d'))
            ->whereDate('posting_date', '<=', $period->end_date->format('Y-m-d'))
            ->exists();

        if ($hasDrafts) {
            throw new DraftEntriesExistException(
                "El período #{$period->period_number} tiene documentos en borrador; deben confirmarse o eliminarse antes de cerrar."
            );
        }

        return DB::transaction(function () use ($company, $period, $executedBy) {
            $period->update(['status' => 'closed']);

            PeriodClosingProcess::create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'type' => 'month_close',
                'executed_by' => $executedBy,
                'executed_at' => now(),
                'status' => 'completed',
            ]);

            return $period->fresh();
        });
    }

    public function reopen(Company $company, FiscalPeriod $period, ?int $executedBy = null): FiscalPeriod
    {
        return DB::transaction(function () use ($company, $period, $executedBy) {
            $period = $this->transition($period, 'closed', 'open');

            PeriodClosingProcess::create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'type' => 'month_reopen',
                'executed_by' => $executedBy,
                'executed_at' => now(),
                'status' => 'completed',
            ]);

            return $period;
        });
    }

    /**
     * Cierra el año: exige que todos los períodos salvo el último ya estén
     * cerrados, contabiliza el asiento de cierre (ACC) que traslada el
     * resultado neto de cuentas de ingreso/gasto a utilidades acumuladas
     * (en LC pura, ver nota en FxRevaluationService sobre localOnly), y
     * cierra el último período junto con el año en la misma operación —
     * resuelve el problema de "dónde se contabiliza el cierre si ya cerré
     * todos los períodos": se contabiliza en el último período, que se deja
     * abierto hasta este paso final.
     */
    public function closeYear(
        Company $company,
        FiscalYear $fiscalYear,
        DocumentType $accDocumentType,
        ChartOfAccount $retainedEarningsAccount,
        ?int $executedBy = null,
    ): PeriodClosingProcess {
        return DB::transaction(function () use ($company, $fiscalYear, $accDocumentType, $retainedEarningsAccount, $executedBy) {
            $periods = FiscalPeriod::where('fiscal_year_id', $fiscalYear->id)->get();

            if ($periods->isEmpty()) {
                throw new InvalidPeriodTransitionException('El año fiscal no tiene períodos configurados.');
            }

            $lastPeriod = $periods->sortByDesc('end_date')->first();

            foreach ($periods as $period) {
                if ($period->id === $lastPeriod->id) {
                    continue;
                }

                if ($period->status !== 'closed') {
                    throw new InvalidPeriodTransitionException(
                        "El período #{$period->period_number} debe estar cerrado antes de cerrar el año."
                    );
                }
            }

            if ($lastPeriod->status !== 'open') {
                throw new InvalidPeriodTransitionException(
                    'El último período del año debe estar abierto: ahí se contabiliza el asiento de cierre anual.'
                );
            }

            $yearStart = $periods->min('start_date');
            $yearEnd = $lastPeriod->end_date;

            [$lines, $totalNet] = $this->buildClosingLines($company, $yearStart, $yearEnd);

            $journalEntry = null;

            if (! empty($lines)) {
                $isNetDebit = bccomp($totalNet, '0.00', 2) > 0;

                $lines[] = new JournalLineInput(
                    accountId: $retainedEarningsAccount->id,
                    currencyId: $company->local_currency_id,
                    debit: $isNetDebit ? $totalNet : 0,
                    credit: $isNetDebit ? 0 : bcmul($totalNet, '-1', 2),
                    description: 'Cierre anual - utilidades acumuladas',
                    localOnly: true,
                );

                $journalEntry = $this->postJournalService->post(
                    $company, $accDocumentType, $yearEnd, $yearEnd, $lines,
                    'Cierre anual '.$fiscalYear->year, $executedBy
                );
            }

            $lastPeriod->update(['status' => 'closed']);
            $fiscalYear->update(['status' => 'closed']);

            return PeriodClosingProcess::create([
                'company_id' => $company->id,
                'fiscal_year_id' => $fiscalYear->id,
                'type' => 'year_close',
                'document_type_id' => $accDocumentType->id,
                'journal_entry_id' => $journalEntry?->id,
                'executed_by' => $executedBy,
                'executed_at' => now(),
                'status' => 'completed',
            ]);
        });
    }

    /**
     * @return array{0: JournalLineInput[], 1: string} líneas que zurran cada
     *         cuenta de resultados a cero, y el neto total (debit-credit) que
     *         debe contrapartirse en utilidades acumuladas.
     */
    private function buildClosingLines(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('account_type', ['income', 'expense'])
            ->where('accepts_posting', true)
            ->get();

        $lines = [];
        $totalNet = '0.00';

        foreach ($accounts as $account) {
            $totals = JournalDetail::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
                ->where('journal_entries.company_id', $company->id)
                // 'voided' cuenta igual que 'posted': ver LedgerService.
                ->whereIn('journal_entries.status', ['posted', 'voided'])
                ->where('journal_details.account_id', $account->id)
                ->whereDate('journal_entries.posting_date', '>=', $from->format('Y-m-d'))
                ->whereDate('journal_entries.posting_date', '<=', $to->format('Y-m-d'))
                ->selectRaw('SUM(journal_details.debit_local) as d, SUM(journal_details.credit_local) as c')
                ->first();

            $debit = number_format((float) ($totals->d ?? 0), 2, '.', '');
            $credit = number_format((float) ($totals->c ?? 0), 2, '.', '');
            $net = bcsub($debit, $credit, 2);

            if (bccomp($net, '0.00', 2) === 0) {
                continue;
            }

            $isNetDebit = bccomp($net, '0.00', 2) > 0;

            // Se contabiliza lo opuesto al neto de la cuenta, para dejarla en cero.
            $lines[] = new JournalLineInput(
                accountId: $account->id,
                currencyId: $company->local_currency_id,
                debit: $isNetDebit ? 0 : bcmul($net, '-1', 2),
                credit: $isNetDebit ? $net : 0,
                description: 'Cierre anual',
                localOnly: true,
            );

            $totalNet = bcadd($totalNet, $net, 2);
        }

        return [$lines, $totalNet];
    }

    private function transition(FiscalPeriod $period, string $from, string $to): FiscalPeriod
    {
        if ($period->status !== $from) {
            throw new InvalidPeriodTransitionException(
                "El período #{$period->period_number} está '{$period->status}', se esperaba '{$from}' para pasar a '{$to}'."
            );
        }

        return DB::transaction(function () use ($period, $to) {
            $period->update(['status' => $to]);

            return $period->fresh();
        });
    }
}
