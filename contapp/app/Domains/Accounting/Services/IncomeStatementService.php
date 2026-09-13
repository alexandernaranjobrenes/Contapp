<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\IncomeStatementResult;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Estado de resultados por naturaleza (secc. 5 de CLAUDE.md): agrupa las
 * cuentas de resultado (income/cost_of_sales/expense/other_income/
 * other_expense) por su clasificación, calculado siempre desde
 * journal_details (nunca almacenado). A diferencia de TrialBalanceService,
 * EXCLUYE el asiento de cierre anual (mismo criterio que LedgerService,
 * docs/decisiones.md 2026-08-27): un estado de resultados reporta la
 * actividad real del período, y el asiento de cierre existe justo para
 * reparte a cero esas mismas cuentas — incluirlo en un rango que lo abarque
 * escondería cuánto se vendió/gastó de verdad.
 */
class IncomeStatementService
{
    private const PL_ACCOUNT_TYPES = ['income', 'cost_of_sales', 'expense', 'other_income', 'other_expense'];

    public function __construct(
        private readonly AccountRollupBuilder $rollupBuilder,
    ) {}

    public function build(Company $company, string $from, string $to, bool $hideZeroMovement = true): IncomeStatementResult
    {
        // Sin el filtro accepts_posting: ver el mismo comentario en
        // BalanceSheetService::build() — las cuentas mayores tienen que
        // entrar para que AccountRollupBuilder les sume sus hojas.
        $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('account_type', self::PL_ACCOUNT_TYPES)
            ->orderBy('code')
            ->get();

        $excludedDocumentTypeIds = DocumentType::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_closing_type', true)
            ->pluck('id');

        $amounts = $this->aggregateAmounts($company, $accounts, $from, $to, $excludedDocumentTypeIds);

        $sections = [];
        foreach (self::PL_ACCOUNT_TYPES as $type) {
            [$lines, $total] = $this->buildSection($accounts, $amounts, $type, $hideZeroMovement);
            $sections[$type] = ['lines' => $lines, 'total' => $total];
        }

        $grossProfit = bcsub($sections['income']['total'], $sections['cost_of_sales']['total'], 2);
        $operatingProfit = bcsub($grossProfit, $sections['expense']['total'], 2);
        $netProfit = bcsub(bcadd($operatingProfit, $sections['other_income']['total'], 2), $sections['other_expense']['total'], 2);

        return new IncomeStatementResult(
            from: $from,
            to: $to,
            sales: $sections['income']['lines'],
            salesTotal: $sections['income']['total'],
            costOfSales: $sections['cost_of_sales']['lines'],
            costOfSalesTotal: $sections['cost_of_sales']['total'],
            grossProfit: $grossProfit,
            operatingExpenses: $sections['expense']['lines'],
            operatingExpensesTotal: $sections['expense']['total'],
            operatingProfit: $operatingProfit,
            otherIncome: $sections['other_income']['lines'],
            otherIncomeTotal: $sections['other_income']['total'],
            otherExpense: $sections['other_expense']['lines'],
            otherExpenseTotal: $sections['other_expense']['total'],
            netProfit: $netProfit,
        );
    }

    private function aggregateAmounts(Company $company, \Illuminate\Support\Collection $accounts, string $from, string $to, \Illuminate\Support\Collection $excludedDocumentTypeIds): \Illuminate\Support\Collection
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
            ->whereDate('journal_entries.posting_date', '>=', $from)
            ->whereDate('journal_entries.posting_date', '<=', $to)
            ->when(
                $excludedDocumentTypeIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('journal_entries.document_type_id', $excludedDocumentTypeIds)
            )
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
    private function buildSection(\Illuminate\Support\Collection $accounts, \Illuminate\Support\Collection $amounts, string $accountType, bool $hideZeroMovement): array
    {
        $accountsOfType = $accounts->where('account_type', $accountType);

        $ownAmounts = $accountsOfType->mapWithKeys(function (ChartOfAccount $account) use ($amounts) {
            $aggregate = $amounts->get($account->id);
            $debit = (string) ($aggregate?->debit ?? '0.00');
            $credit = (string) ($aggregate?->credit ?? '0.00');

            $amount = $account->normal_balance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);

            return [$account->id => $amount];
        });

        return $this->rollupBuilder->build($accountsOfType, $ownAmounts, $hideZeroMovement);
    }
}
