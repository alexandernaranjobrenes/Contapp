<?php

namespace App\Domains\Core\Dashboard;

use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Ingresos, gastos y resultado de cada uno de los últimos meses, en moneda
 * local: el estado de resultados (IncomeStatementService) mes por mes, con
 * su mismo criterio, en una sola consulta.
 *
 * - Cuentas de resultado activas, cada una según su naturaleza (débito −
 *   crédito si es deudora, al revés si es acreedora), igual que el reporte.
 *   Ingresos y otros ingresos suman al ingreso; costo de ventas, gastos y
 *   otros gastos, al gasto. Así el resultado del mes es el del reporte.
 * - Asientos contabilizados y anulados (el anulado y su reversión se
 *   compensan), sin el asiento de cierre anual: el cierre lleva a cero las
 *   cuentas de resultado y escondería lo vendido y gastado de verdad.
 */
final class MonthlyResults
{
    private const INCOME_TYPES = ['income', 'other_income'];

    private const EXPENSE_TYPES = ['cost_of_sales', 'expense', 'other_expense'];

    /**
     * @return list<array{month: string, label: string, title: string, income: float, expenses: float, result: float}>
     *                                                                                                                  del más viejo al actual
     */
    public static function for(DashboardContext $context, int $months = 12): array
    {
        return $context->remember("monthly-results:{$months}", function () use ($context, $months) {
            $company = $context->company;
            $first = $context->today->copy()->startOfMonth()->subMonths($months - 1);

            $closingTypes = DocumentType::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('is_closing_type', true)
                ->pluck('id');

            // El mes, como texto «2026-10»: SUBSTR anda igual en MySQL,
            // MariaDB y SQLite.
            $month = 'SUBSTR(journal_entries.posting_date, 1, 7)';

            $rows = DB::table('journal_details')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_details.account_id')
                ->where('journal_entries.company_id', $company->id)
                ->whereIn('journal_entries.status', ['posted', 'voided'])
                ->where('chart_of_accounts.is_active', true)
                ->whereIn('chart_of_accounts.account_type', [...self::INCOME_TYPES, ...self::EXPENSE_TYPES])
                ->whereDate('journal_entries.posting_date', '>=', $first->toDateString())
                ->whereDate('journal_entries.posting_date', '<=', $context->today->toDateString())
                ->when($closingTypes->isNotEmpty(), fn ($q) => $q->whereNotIn('journal_entries.document_type_id', $closingTypes))
                ->groupBy('chart_of_accounts.account_type', 'chart_of_accounts.normal_balance', DB::raw($month))
                ->selectRaw("chart_of_accounts.account_type as type, chart_of_accounts.normal_balance as nature, {$month} as ym,
                    COALESCE(SUM(journal_details.debit_local), 0) as debit,
                    COALESCE(SUM(journal_details.credit_local), 0) as credit")
                ->get();

            $byMonth = [];
            foreach ($rows as $row) {
                $byMonth[$row->ym] ??= ['income' => '0.00', 'expenses' => '0.00'];

                $amount = $row->nature === 'debit'
                    ? bcsub((string) $row->debit, (string) $row->credit, 2)
                    : bcsub((string) $row->credit, (string) $row->debit, 2);
                $side = in_array($row->type, self::INCOME_TYPES, true) ? 'income' : 'expenses';

                $byMonth[$row->ym][$side] = bcadd($byMonth[$row->ym][$side], $amount, 2);
            }

            $result = [];
            for ($i = 0; $i < $months; $i++) {
                $date = $first->copy()->addMonths($i);
                $key = $date->format('Y-m');
                $income = $byMonth[$key]['income'] ?? '0.00';
                $expenses = $byMonth[$key]['expenses'] ?? '0.00';

                $result[] = [
                    'month' => $key,
                    ...DashboardContext::monthLabels($date),
                    'income' => DashboardContext::money($income),
                    'expenses' => DashboardContext::money($expenses),
                    'result' => DashboardContext::money(bcsub($income, $expenses, 2)),
                ];
            }

            return $result;
        });
    }
}
