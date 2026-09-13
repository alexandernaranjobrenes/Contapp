<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\TrialBalanceResult;
use App\Domains\Accounting\DataTransferObjects\TrialBalanceRow;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Balance de comprobación: saldo inicial + movimiento del período + saldo
 * final por cuenta hoja, calculado siempre desde journal_details (nunca
 * almacenado, ver docs/decisiones.md) con UNA sola consulta agregada para
 * todas las cuentas de la compañía — evita el patrón N+1 que tendría
 * reusar LedgerService por cuenta, relevante porque este reporte crece con
 * multiempresa y muchos periodos.
 *
 * A diferencia de LedgerService, no excluye el asiento de cierre anual: en
 * un balance de comprobación es correcto que las cuentas de resultado
 * lleguen a cero tras el cierre.
 */
class TrialBalanceService
{
    public function __construct(
        private readonly AccountRollupBuilder $rollupBuilder,
    ) {}

    public function build(Company $company, ?string $from, ?string $to, bool $hideZeroMovement = true): TrialBalanceResult
    {
        // Sin el filtro accepts_posting: ver el comentario en
        // BalanceSheetService::build() — las cuentas mayores tienen que
        // entrar para que se les sume el total de sus hojas.
        $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return new TrialBalanceResult($from, $to, [], '0.00', '0.00');
        }

        // Sentinelas en vez de armar SQL condicional: si $from es null, nada
        // queda "antes" de 0001-01-01 (saldo inicial correctamente en 0) y el
        // período arranca desde el principio de los tiempos; si $to es null,
        // el período no tiene límite superior real.
        $openingCutoff = $from ?? '0001-01-01';
        $periodFrom = $from ?? '0001-01-01';
        $periodTo = $to ?? '9999-12-31';

        $aggregates = DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            // 'voided' cuenta igual que 'posted': su reversión (espejo con
            // signo contrario) también contabiliza, y ambos deben sumar
            // para netear en cero — excluir el original dejaría el espejo
            // sin nada que cancelar (bug real corregido 2026-09-09).
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereIn('journal_details.account_id', $accounts->pluck('id'))
            ->groupBy('journal_details.account_id')
            ->selectRaw(
                'journal_details.account_id,
                 COALESCE(SUM(CASE WHEN journal_entries.posting_date < ? THEN journal_details.debit_local ELSE 0 END), 0) as opening_debit,
                 COALESCE(SUM(CASE WHEN journal_entries.posting_date < ? THEN journal_details.credit_local ELSE 0 END), 0) as opening_credit,
                 COALESCE(SUM(CASE WHEN journal_entries.posting_date >= ? AND journal_entries.posting_date <= ? THEN journal_details.debit_local ELSE 0 END), 0) as period_debit,
                 COALESCE(SUM(CASE WHEN journal_entries.posting_date >= ? AND journal_entries.posting_date <= ? THEN journal_details.credit_local ELSE 0 END), 0) as period_credit',
                [$openingCutoff, $openingCutoff, $periodFrom, $periodTo, $periodFrom, $periodTo]
            )
            ->get()
            ->keyBy('account_id');

        // Montos propios por cuenta (una cuenta mayor nunca aparece acá con
        // algo distinto de 0.00, porque nunca recibe asientos directos) —
        // insumo para AccountRollupBuilder::sumByAccount(), llamado una vez
        // por columna (a diferencia de BalanceSheetService/IncomeStatementService,
        // que solo necesitan un monto por línea, acá son 4 independientes).
        $ownOpeningDebit = $accounts->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => (string) ($aggregates->get($a->id)?->opening_debit ?? '0.00')]);
        $ownOpeningCredit = $accounts->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => (string) ($aggregates->get($a->id)?->opening_credit ?? '0.00')]);
        $ownPeriodDebit = $accounts->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => (string) ($aggregates->get($a->id)?->period_debit ?? '0.00')]);
        $ownPeriodCredit = $accounts->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => (string) ($aggregates->get($a->id)?->period_credit ?? '0.00')]);

        $rolledOpeningDebit = $this->rollupBuilder->sumByAccount($accounts, $ownOpeningDebit);
        $rolledOpeningCredit = $this->rollupBuilder->sumByAccount($accounts, $ownOpeningCredit);
        $rolledPeriodDebit = $this->rollupBuilder->sumByAccount($accounts, $ownPeriodDebit);
        $rolledPeriodCredit = $this->rollupBuilder->sumByAccount($accounts, $ownPeriodCredit);

        $rows = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($accounts->where('accepts_posting', true) as $leaf) {
            $totalDebit = bcadd($totalDebit, $ownPeriodDebit->get($leaf->id, '0.00'), 2);
            $totalCredit = bcadd($totalCredit, $ownPeriodCredit->get($leaf->id, '0.00'), 2);
        }

        foreach ($accounts as $account) {
            $openingDebit = $rolledOpeningDebit->get($account->id, '0.00');
            $openingCredit = $rolledOpeningCredit->get($account->id, '0.00');
            $periodDebit = $rolledPeriodDebit->get($account->id, '0.00');
            $periodCredit = $rolledPeriodCredit->get($account->id, '0.00');

            $openingBalance = $this->signedBalance($account->normal_balance, $openingDebit, $openingCredit);
            $periodBalance = $this->signedBalance($account->normal_balance, $periodDebit, $periodCredit);
            $closingBalance = bcadd($openingBalance, $periodBalance, 2);

            if ($hideZeroMovement
                && $openingBalance === '0.00'
                && $periodDebit === '0.00'
                && $periodCredit === '0.00'
                && $closingBalance === '0.00'
            ) {
                continue;
            }

            $rows[] = new TrialBalanceRow(
                accountId: $account->id,
                code: $account->code,
                description: $account->description_es,
                accountType: $account->account_type,
                openingBalance: $openingBalance,
                periodDebit: number_format((float) $periodDebit, 2, '.', ''),
                periodCredit: number_format((float) $periodCredit, 2, '.', ''),
                periodNet: $periodBalance,
                closingBalance: $closingBalance,
                depth: substr_count($account->code, '-'),
                isHeader: ! $account->accepts_posting,
            );
        }

        return new TrialBalanceResult($from, $to, $rows, $totalDebit, $totalCredit);
    }

    private function signedBalance(string $normalBalance, string $debit, string $credit): string
    {
        return $normalBalance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }
}
