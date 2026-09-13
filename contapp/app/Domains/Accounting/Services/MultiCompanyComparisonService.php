<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\MultiCompanyComparisonResult;
use App\Domains\Accounting\DataTransferObjects\MultiCompanyComparisonRow;
use App\Domains\Core\Models\Company;
use Illuminate\Support\Collection;

/**
 * Comparativo entre empresas del mismo grupo (CLAUDE.md secc. 5): no es una
 * CONSOLIDACIÓN contable real (eso exigiría un catálogo de cuentas común o
 * una tabla de mapeo entre catálogos de cada empresa, que este proyecto no
 * tiene — cada compañía define su propio plan de cuentas de forma
 * independiente). Es un comparativo lado a lado: reutiliza BalanceSheetService
 * e IncomeStatementService, ya probados, sobre cada compañía por separado, y
 * nunca suma cifras entre compañías (cada una puede tener una moneda
 * distinta) — misma regla ya aplicada en AgingService/CashFlowProjectionService.
 */
class MultiCompanyComparisonService
{
    public function __construct(
        private readonly BalanceSheetService $balanceSheetService,
        private readonly IncomeStatementService $incomeStatementService,
    ) {}

    /**
     * @param  Collection<int, Company>  $companies
     */
    public function build(Collection $companies, string $asOf, string $from, string $to): MultiCompanyComparisonResult
    {
        $rows = $companies->map(function (Company $company) use ($asOf, $from, $to) {
            $balanceSheet = $this->balanceSheetService->build($company, $asOf);
            $incomeStatement = $this->incomeStatementService->build($company, $from, $to);

            return new MultiCompanyComparisonRow(
                companyId: $company->id,
                companyName: $company->trade_name ?: $company->legal_name,
                currencyCode: $company->localCurrency?->code ?? '—',
                assetsTotal: $balanceSheet->assetsTotal,
                liabilitiesTotal: $balanceSheet->liabilitiesTotal,
                equityTotal: $balanceSheet->totalEquityAndEarnings,
                salesTotal: $incomeStatement->salesTotal,
                netProfit: $incomeStatement->netProfit,
            );
        })->values()->all();

        return new MultiCompanyComparisonResult($asOf, $from, $to, $rows);
    }
}
