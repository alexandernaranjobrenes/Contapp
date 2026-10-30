<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * El saldo en libros de cada cuenta bancaria, hoy: el de su cuenta contable,
 * con el criterio del libro mayor (contabilizados y anulados). Las barras
 * comparan en moneda local; una cuenta en otra moneda dice además su saldo
 * en esa moneda. El número de cuenta va con sus últimos cuatro dígitos.
 */
class CashBalancesWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'cash_balances';
    }

    public function title(): string
    {
        return 'Saldos en bancos';
    }

    public function description(): string
    {
        return 'El saldo en libros de cada cuenta bancaria, hoy, en moneda local.';
    }

    public function section(): string
    {
        return 'banking';
    }

    public function kind(): string
    {
        return 'bars';
    }

    public function screens(): array
    {
        return ['banking.accounts'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('bank-accounts.index');
    }

    public function data(DashboardContext $context): array
    {
        $company = $context->company;

        $accounts = BankAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with('currency:id,code,symbol')
            ->orderBy('bank_name')
            ->get();

        $balances = DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereDate('journal_entries.posting_date', '<=', $context->today->toDateString())
            ->whereIn('journal_details.account_id', $accounts->pluck('gl_account_id')->filter()->unique())
            ->groupBy('journal_details.account_id')
            ->selectRaw('journal_details.account_id,
                COALESCE(SUM(journal_details.debit_local), 0) - COALESCE(SUM(journal_details.credit_local), 0) as local,
                COALESCE(SUM(journal_details.debit_foreign), 0) - COALESCE(SUM(journal_details.credit_foreign), 0) as foreign_amount')
            ->get()
            ->keyBy('account_id');

        $localCurrencyId = $company->local_currency_id;
        $total = 0.0;

        $rows = $accounts->map(function (BankAccount $account) use ($balances, $localCurrencyId, &$total) {
            $balance = $balances->get($account->gl_account_id);
            $local = DashboardContext::money($balance?->local);
            $total += $local;
            $digits = preg_replace('/\D/', '', (string) $account->account_number);

            return [
                'label' => trim($account->bank_name.($digits !== '' ? ' •••• '.substr($digits, -4) : '')),
                'value' => $local,
                'secondary' => $account->currency_id !== null && $account->currency_id !== $localCurrencyId && $account->currency
                    ? ['value' => DashboardContext::money($balance?->foreign_amount), 'currency' => ['code' => $account->currency->code, 'symbol' => $account->currency->symbol]]
                    : null,
            ];
        })->values()->all();

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'rows' => $rows,
            'total' => ['label' => 'Total en bancos', 'value' => DashboardContext::money($total)],
            'empty' => 'Todavía no hay cuentas bancarias registradas.',
        ];
    }
}
