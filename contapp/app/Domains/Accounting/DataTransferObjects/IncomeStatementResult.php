<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class IncomeStatementResult implements JsonSerializable
{
    /**
     * @param  IncomeStatementLine[]  $sales
     * @param  IncomeStatementLine[]  $costOfSales
     * @param  IncomeStatementLine[]  $operatingExpenses
     * @param  IncomeStatementLine[]  $otherIncome
     * @param  IncomeStatementLine[]  $otherExpense
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $sales,
        public readonly string $salesTotal,
        public readonly array $costOfSales,
        public readonly string $costOfSalesTotal,
        public readonly string $grossProfit,
        public readonly array $operatingExpenses,
        public readonly string $operatingExpensesTotal,
        public readonly string $operatingProfit,
        public readonly array $otherIncome,
        public readonly string $otherIncomeTotal,
        public readonly array $otherExpense,
        public readonly string $otherExpenseTotal,
        public readonly string $netProfit,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
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
