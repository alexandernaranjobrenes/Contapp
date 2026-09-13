<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\MissingExchangeRateException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FxRevaluationDetail;
use App\Domains\Accounting\Models\FxRevaluationRun;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Diferencial cambiario de cierre (no realizado): compara, cuenta por cuenta
 * y socio por socio, el saldo en moneda extranjera traducido al tipo de
 * cambio histórico de cada línea contra el tipo de cambio de la fecha de
 * corte. La diferencia se contabiliza vía PostJournalService con líneas
 * localOnly (el saldo en FC no cambia, solo su equivalente en LC) — así el
 * motor de partida doble no necesita ningún caso especial para esto.
 *
 * Flujo en dos pasos, estilo SAP B1 (docs/decisiones.md 2026-08-24):
 * preview() calcula sin contabilizar nada, para que el usuario revise línea
 * por línea y decida cuáles incluir; execute() vuelve a calcular desde cero
 * (no confía en montos que haya mandado el cliente) y contabiliza solo los
 * pares cuenta+socio confirmados.
 */
class FxRevaluationService
{
    public function __construct(private readonly PostJournalService $postJournalService)
    {
    }

    /**
     * @param  int[]|null  $accountIds  Filtra las cuentas "de mayor" (sin socio) a incluir; null = todas
     * @param  int[]|null  $businessPartnerIds  Filtra los socios a incluir; null = todos
     * @return array<int, array{account_id:int, business_partner_id:?int, foreign_balance:string, historical_local_amount:string, revalued_local_amount:string, difference:string}>
     */
    public function preview(
        Company $company,
        \DateTimeInterface $cutoffDate,
        bool $includeAccounts = true,
        bool $includeBusinessPartners = true,
        ?array $accountIds = null,
        ?array $businessPartnerIds = null,
    ): array {
        if (! $includeAccounts && ! $includeBusinessPartners) {
            return [];
        }

        $closingRate = $this->closingRate($company, $cutoffDate);
        $groups = $this->matchingGroups($company, $cutoffDate, $includeAccounts, $includeBusinessPartners, $accountIds, $businessPartnerIds);

        return $this->computeRows($groups, $closingRate);
    }

    /**
     * @param  array<int, array{account_id:int, business_partner_id:?int}>  $selectedGroups  Pares confirmados en la pantalla de revisión (ver preview())
     */
    public function execute(
        Company $company,
        DocumentType $documentType,
        \DateTimeInterface $cutoffDate,
        ChartOfAccount $gainAccount,
        ChartOfAccount $lossAccount,
        array $selectedGroups,
        ?int $executedBy = null,
    ): FxRevaluationRun {
        if (empty($selectedGroups)) {
            throw new \InvalidArgumentException('Hay que seleccionar al menos una cuenta o socio de negocio para revaluar.');
        }

        $closingRate = $this->closingRate($company, $cutoffDate);

        $accountIds = collect($selectedGroups)->pluck('account_id')->unique()->values()->all();
        $selectedKeys = collect($selectedGroups)
            ->map(fn (array $g) => $this->groupKey((int) $g['account_id'], isset($g['business_partner_id']) ? (int) $g['business_partner_id'] : null))
            ->all();

        // Recalcula desde cero sobre TODOS los grupos de esas cuentas (no
        // solo los seleccionados): así, si el saldo cambió entre el preview
        // y este momento, igual se contabiliza el monto correcto — nunca el
        // que haya mandado el cliente.
        $groups = $this->matchingGroups($company, $cutoffDate, true, true, $accountIds, null);
        $rows = $this->computeRows($groups, $closingRate);
        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => in_array($this->groupKey($row['account_id'], $row['business_partner_id']), $selectedKeys, true)
        ));

        if (empty($rows)) {
            return FxRevaluationRun::create([
                'company_id' => $company->id,
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'exchange_rate_used' => $closingRate,
                'gain_account_id' => $gainAccount->id,
                'loss_account_id' => $lossAccount->id,
                'document_type_id' => $documentType->id,
                'status' => 'completed',
                'executed_by' => $executedBy,
                'executed_at' => now(),
            ]);
        }

        $lines = [];

        foreach ($rows as $row) {
            $isGain = bccomp($row['difference'], '0.00', 2) > 0;
            $absDifference = $isGain ? $row['difference'] : bcmul($row['difference'], '-1', 2);

            // Línea propia: ajusta SOLO el equivalente en LC de esta cuenta
            // (localOnly) — el saldo en FC no se toca.
            $lines[] = new JournalLineInput(
                accountId: $row['account_id'],
                currencyId: $company->local_currency_id,
                debit: $isGain ? $absDifference : 0,
                credit: $isGain ? 0 : $absDifference,
                description: 'Diferencial cambiario',
                businessPartnerId: $row['business_partner_id'],
                localOnly: true,
            );

            // Contrapartida DE ESTE GRUPO (no neteada contra los demás, para
            // que ganancias y pérdidas queden brutas y trazables por cuenta):
            // ganancia = crédito a resultados; pérdida = débito.
            $lines[] = new JournalLineInput(
                accountId: $isGain ? $gainAccount->id : $lossAccount->id,
                currencyId: $company->local_currency_id,
                debit: $isGain ? 0 : $absDifference,
                credit: $isGain ? $absDifference : 0,
                description: 'Diferencial cambiario - contrapartida',
                localOnly: true,
            );
        }

        $journalEntry = $this->postJournalService->post(
            $company, $documentType, $cutoffDate, $cutoffDate, $lines,
            'Diferencial cambiario al '.$cutoffDate->format('Y-m-d'), $executedBy
        );

        $run = FxRevaluationRun::create([
            'company_id' => $company->id,
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'exchange_rate_used' => $closingRate,
            'gain_account_id' => $gainAccount->id,
            'loss_account_id' => $lossAccount->id,
            'document_type_id' => $documentType->id,
            'journal_entry_id' => $journalEntry->id,
            'status' => 'completed',
            'executed_by' => $executedBy,
            'executed_at' => now(),
        ]);

        foreach ($rows as $i => $row) {
            // Cada grupo aportó 2 líneas (propia + contrapartida); la propia
            // quedó en la posición impar (1, 3, 5...) del asiento.
            $ownLineNumber = 2 * $i + 1;
            $ownDetail = $journalEntry->details->firstWhere('line_number', $ownLineNumber);

            FxRevaluationDetail::create([
                'fx_revaluation_run_id' => $run->id,
                'account_id' => $row['account_id'],
                'business_partner_id' => $row['business_partner_id'],
                'foreign_balance' => $row['foreign_balance'],
                'historical_local_amount' => $row['historical_local_amount'],
                'revalued_local_amount' => $row['revalued_local_amount'],
                'difference' => $row['difference'],
                'journal_detail_id' => $ownDetail?->id,
            ]);

            // La base de comparación para el diferencial REALIZADO de las
            // partidas abiertas de este socio en esta cuenta pasa a ser el
            // tipo de cambio de ESTA revaluación — si no, ApplyPaymentService
            // volvería a contar esta misma porción como realizada más
            // adelante (ver docstring de realizedFxDifference()).
            if ($row['business_partner_id']) {
                BpOpenItem::whereHas('originJournalDetail', fn ($q) => $q->where('account_id', $row['account_id']))
                    ->where('business_partner_id', $row['business_partner_id'])
                    ->where('status', '!=', 'closed')
                    ->update(['last_revaluation_rate' => $closingRate]);
            }
        }

        return $run->load('details');
    }

    /**
     * @param  int[]|null  $accountIds
     * @param  int[]|null  $businessPartnerIds
     */
    private function matchingGroups(
        Company $company,
        \DateTimeInterface $cutoffDate,
        bool $includeAccounts,
        bool $includeBusinessPartners,
        ?array $accountIds,
        ?array $businessPartnerIds,
    ) {
        $query = JournalDetail::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_details.account_id')
            ->where('journal_entries.company_id', $company->id)
            // 'voided' cuenta igual que 'posted': ver LedgerService.
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereDate('journal_entries.posting_date', '<=', $cutoffDate->format('Y-m-d'))
            ->whereIn('chart_of_accounts.currency_mode', ['foreign', 'both']);

        // "Cuentas de mayor" y "Socio de negocios" son dos universos
        // independientes (igual que en SAP B1): el filtro de cuentas solo
        // acota el primero, el de socios solo el segundo.
        $query->where(function ($q) use ($includeAccounts, $includeBusinessPartners, $accountIds, $businessPartnerIds) {
            if ($includeAccounts) {
                $q->orWhere(function ($qq) use ($accountIds) {
                    $qq->whereNull('journal_details.business_partner_id');

                    if ($accountIds !== null) {
                        $qq->whereIn('journal_details.account_id', $accountIds);
                    }
                });
            }

            if ($includeBusinessPartners) {
                $q->orWhere(function ($qq) use ($businessPartnerIds) {
                    $qq->whereNotNull('journal_details.business_partner_id');

                    if ($businessPartnerIds !== null) {
                        $qq->whereIn('journal_details.business_partner_id', $businessPartnerIds);
                    }
                });
            }
        });

        return $query
            ->groupBy('journal_details.account_id', 'journal_details.business_partner_id')
            ->get([
                'journal_details.account_id',
                'journal_details.business_partner_id',
                DB::raw('SUM(journal_details.debit_foreign - journal_details.credit_foreign) as fc_balance'),
                DB::raw('SUM(journal_details.debit_local - journal_details.credit_local) as historical_local'),
            ]);
    }

    /**
     * @return array<int, array{account_id:int, business_partner_id:?int, foreign_balance:string, historical_local_amount:string, revalued_local_amount:string, difference:string}>
     */
    private function computeRows($groups, string $closingRate): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $fcBalance = $this->money((string) $group->fc_balance);

            if (bccomp($fcBalance, '0.00', 2) === 0) {
                continue;
            }

            $historicalLocal = $this->money((string) $group->historical_local);
            $revaluedLocal = $this->money(bcmul($fcBalance, $closingRate, 10));
            $difference = bcsub($revaluedLocal, $historicalLocal, 2);

            if (bccomp($difference, '0.00', 2) === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => (int) $group->account_id,
                'business_partner_id' => $group->business_partner_id ? (int) $group->business_partner_id : null,
                'foreign_balance' => $fcBalance,
                'historical_local_amount' => $historicalLocal,
                'revalued_local_amount' => $revaluedLocal,
                'difference' => $difference,
            ];
        }

        return $rows;
    }

    private function groupKey(int $accountId, ?int $businessPartnerId): string
    {
        return "{$accountId}:".($businessPartnerId ?? '');
    }

    /**
     * Público para que el controlador pueda mostrar el tipo de cambio que
     * se va a aplicar en la pantalla de revisión, sin duplicar la consulta.
     */
    public function closingRate(Company $company, \DateTimeInterface $date): string
    {
        $rate = ExchangeRate::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('currency_id', $company->foreign_currency_id)
            ->whereDate('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();

        if (! $rate) {
            throw new MissingExchangeRateException(
                "No hay tipo de cambio de cierre disponible para la fecha {$date->format('Y-m-d')}."
            );
        }

        return (string) $rate->rate;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
