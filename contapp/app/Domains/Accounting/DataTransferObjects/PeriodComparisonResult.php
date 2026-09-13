<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class PeriodComparisonResult implements JsonSerializable
{
    /**
     * @param  PeriodComparisonLine[]  $assets
     * @param  PeriodComparisonLine[]  $liabilities
     * @param  PeriodComparisonLine[]  $equity
     * @param  PeriodComparisonLine[]  $sales
     * @param  PeriodComparisonLine[]  $costOfSales
     * @param  PeriodComparisonLine[]  $operatingExpenses
     * @param  PeriodComparisonLine[]  $otherIncome
     * @param  PeriodComparisonLine[]  $otherExpense
     */
    public function __construct(
        public readonly string $from1,
        public readonly string $to1,
        public readonly string $from2,
        public readonly string $to2,
        public readonly array $assets,
        public readonly PeriodComparisonTotal $assetsTotal,
        public readonly array $liabilities,
        public readonly PeriodComparisonTotal $liabilitiesTotal,
        public readonly array $equity,
        public readonly PeriodComparisonTotal $equityTotal,
        public readonly PeriodComparisonTotal $totalLiabilitiesAndEquity,
        public readonly array $sales,
        public readonly PeriodComparisonTotal $salesTotal,
        public readonly array $costOfSales,
        public readonly PeriodComparisonTotal $costOfSalesTotal,
        public readonly PeriodComparisonTotal $grossProfit,
        public readonly array $operatingExpenses,
        public readonly PeriodComparisonTotal $operatingExpensesTotal,
        public readonly PeriodComparisonTotal $operatingProfit,
        public readonly array $otherIncome,
        public readonly PeriodComparisonTotal $otherIncomeTotal,
        public readonly array $otherExpense,
        public readonly PeriodComparisonTotal $otherExpenseTotal,
        public readonly PeriodComparisonTotal $netProfit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'from_1' => $this->from1,
            'to_1' => $this->to1,
            'from_2' => $this->from2,
            'to_2' => $this->to2,
            'assets' => $this->assets,
            'assets_total' => $this->assetsTotal,
            'liabilities' => $this->liabilities,
            'liabilities_total' => $this->liabilitiesTotal,
            'equity' => $this->equity,
            'equity_total' => $this->equityTotal,
            'total_liabilities_and_equity' => $this->totalLiabilitiesAndEquity,
            'sales' => $this->sales,
            'sales_total' => $this->salesTotal,
            'cost_of_sales' => $this->costOfSales,
            'cost_of_sales_total' => $this->costOfSalesTotal,
            'gross_profit' => $this->grossProfit,
            'operating_expenses' => $this->operatingExpenses,
            'operating_expenses_total' => $this->operatingExpensesTotal,
            'operating_profit' => $this->operatingProfit,
            'other_income' => $this->otherIncome,
            'other_income_total' => $this->otherIncomeTotal,
            'other_expense' => $this->otherExpense,
            'other_expense_total' => $this->otherExpenseTotal,
            'net_profit' => $this->netProfit,
        ];
    }
}
