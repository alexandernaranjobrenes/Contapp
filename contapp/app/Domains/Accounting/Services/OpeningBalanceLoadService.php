<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Exceptions\UnbalancedOpeningBalancesException;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\OpeningBalance;
use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Carga de saldos iniciales para puesta en marcha de una compañía nueva o
 * migración desde un sistema legado. No genera un asiento contable — los
 * opening_balances son la base sobre la que se calculan los saldos junto con
 * los movimientos del año (CLAUDE.md: los saldos SIEMPRE se calculan, nunca
 * se almacenan; esto no es la excepción, solo es el punto de partida).
 */
class OpeningBalanceLoadService
{
    /**
     * @param  array<int, array{account_id:int, business_partner_id?:int|null, debit_local?:string|float|int, credit_local?:string|float|int, debit_foreign?:string|float|int, credit_foreign?:string|float|int, currency_id?:int|null}>  $balances
     * @return OpeningBalance[]
     */
    public function load(Company $company, FiscalYear $fiscalYear, array $balances, ?int $importedBy = null): array
    {
        if (empty($balances)) {
            throw new \InvalidArgumentException('Se requiere al menos un saldo inicial.');
        }

        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($balances as $balance) {
            $totalDebit = bcadd($totalDebit, number_format((float) ($balance['debit_local'] ?? 0), 2, '.', ''), 2);
            $totalCredit = bcadd($totalCredit, number_format((float) ($balance['credit_local'] ?? 0), 2, '.', ''), 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new UnbalancedOpeningBalancesException(
                "Los saldos iniciales no cuadran en moneda local: débitos {$totalDebit} vs créditos {$totalCredit}."
            );
        }

        return DB::transaction(function () use ($company, $fiscalYear, $balances, $importedBy) {
            $rows = [];

            foreach ($balances as $balance) {
                $rows[] = OpeningBalance::create(array_merge($balance, [
                    'company_id' => $company->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'imported_by' => $importedBy,
                    'imported_at' => now(),
                ]));
            }

            return $rows;
        });
    }
}
