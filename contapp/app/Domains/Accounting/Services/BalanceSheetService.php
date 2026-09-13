<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\BalanceSheetResult;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Balance general / estado de situación financiera: saldo de cada cuenta de
 * activo/pasivo/patrimonio A UNA FECHA (no un rango, a diferencia del estado
 * de resultados), calculado siempre desde journal_details.
 *
 * La pieza no trivial: mientras el año fiscal sigue abierto, la utilidad del
 * ejercicio todavía no está "en" ninguna cuenta de patrimonio — recién se
 * traslada ahí con el asiento de cierre anual (PeriodCloseService::closeYear).
 * Sin ese traslado, Activo no cuadraría contra Pasivo + Patrimonio en un
 * balance de un año todavía abierto. Por eso este servicio calcula la
 * utilidad acumulada del año a la fecha (reutilizando IncomeStatementService)
 * y la suma como una línea de patrimonio computada — PERO solo si el cierre
 * de ese año no se ha contabilizado todavía en el rango consultado: si ya
 * se contabilizó, la utilidad ya está reflejada en la cuenta de utilidades
 * acumuladas y sumarla de nuevo la duplicaría.
 */
class BalanceSheetService
{
    public function __construct(
        private readonly IncomeStatementService $incomeStatementService,
        private readonly AccountRollupBuilder $rollupBuilder,
    ) {}

    public function build(Company $company, string $asOf): BalanceSheetResult
    {
        // Sin el filtro accepts_posting: las cuentas mayores (accepts_posting
        // false, ej. "1-01-01" CAJA Y BANCOS) tienen que entrar al cálculo
        // para que buildSection()/AccountRollupBuilder les sume el total de
        // sus hojas — antes quedaban excluidas de raíz y el reporte solo
        // mostraba cuentas hoja sueltas, sin ninguna cuenta mayor.
        $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('account_type', ['asset', 'liability', 'equity'])
            ->orderBy('code')
            ->get();

        $amounts = $this->aggregateAsOf($company, $accounts, $asOf);

        [$assets, $assetsTotal] = $this->buildSection($accounts, $amounts, 'asset');
        [$liabilities, $liabilitiesTotal] = $this->buildSection($accounts, $amounts, 'liability');
        [$equity, $equityTotal] = $this->buildSection($accounts, $amounts, 'equity');

        $currentYearEarnings = $this->resolveCurrentYearEarnings($company, $asOf);

        $totalEquityAndEarnings = bcadd($equityTotal, $currentYearEarnings, 2);
        $totalLiabilitiesAndEquity = bcadd($liabilitiesTotal, $totalEquityAndEarnings, 2);

        return new BalanceSheetResult(
            asOf: $asOf,
            assets: $assets,
            assetsTotal: $assetsTotal,
            liabilities: $liabilities,
            liabilitiesTotal: $liabilitiesTotal,
            equity: $equity,
            equityTotal: $equityTotal,
            currentYearEarnings: $currentYearEarnings,
            totalEquityAndEarnings: $totalEquityAndEarnings,
            totalLiabilitiesAndEquity: $totalLiabilitiesAndEquity,
            isBalanced: bccomp($assetsTotal, $totalLiabilitiesAndEquity, 2) === 0,
        );
    }

    private function aggregateAsOf(Company $company, Collection $accounts, string $asOf): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        return DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            // 'voided' cuenta igual que 'posted': ver LedgerService.
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereIn('journal_details.account_id', $accounts->pluck('id'))
            ->whereDate('journal_entries.posting_date', '<=', $asOf)
            ->groupBy('journal_details.account_id')
            ->selectRaw('journal_details.account_id,
                 COALESCE(SUM(journal_details.debit_local), 0) as debit,
                 COALESCE(SUM(journal_details.credit_local), 0) as credit')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * @return array{0: \App\Domains\Accounting\DataTransferObjects\IncomeStatementLine[], 1: string}
     */
    private function buildSection(Collection $accounts, Collection $amounts, string $accountType): array
    {
        $accountsOfType = $accounts->where('account_type', $accountType);

        $ownAmounts = $accountsOfType->mapWithKeys(function (ChartOfAccount $account) use ($amounts) {
            $aggregate = $amounts->get($account->id);
            $debit = (string) ($aggregate?->debit ?? '0.00');
            $credit = (string) ($aggregate?->credit ?? '0.00');

            $amount = $account->normal_balance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);

            return [$account->id => $amount];
        });

        // hideZero: true siempre — mismo comportamiento que ya tenía este
        // reporte (a diferencia del estado de resultados, acá no hay toggle
        // de "mostrar cuentas sin movimiento").
        return $this->rollupBuilder->build($accountsOfType, $ownAmounts, true);
    }

    /**
     * Utilidad acumulada del año fiscal que contiene $asOf, calculada desde
     * el inicio real de ese año (el primer período que le pertenece, no un
     * supuesto 1 de enero — un año fiscal no necesariamente es calendario).
     * Devuelve '0.00' si no hay año fiscal que cubra la fecha, o si el
     * cierre anual de ese año ya se contabilizó dentro del rango (ya está
     * reflejado en la cuenta de utilidades acumuladas).
     */
    private function resolveCurrentYearEarnings(Company $company, string $asOf): string
    {
        $period = DB::table('fiscal_periods')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'fiscal_periods.fiscal_year_id')
            ->where('fiscal_years.company_id', $company->id)
            ->whereDate('fiscal_periods.start_date', '<=', $asOf)
            ->whereDate('fiscal_periods.end_date', '>=', $asOf)
            ->select('fiscal_periods.fiscal_year_id')
            ->first();

        if ($period === null) {
            return '0.00';
        }

        $yearStart = DB::table('fiscal_periods')
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->min('start_date');

        $closingAlreadyRan = DB::table('journal_entries')
            ->join('document_types', 'document_types.id', '=', 'journal_entries.document_type_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.status', 'posted')
            ->where('document_types.is_closing_type', true)
            ->whereDate('journal_entries.posting_date', '>=', $yearStart)
            ->whereDate('journal_entries.posting_date', '<=', $asOf)
            ->exists();

        if ($closingAlreadyRan) {
            return '0.00';
        }

        return $this->incomeStatementService->build($company, $yearStart, $asOf)->netProfit;
    }
}
