<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\IncomeStatementLine;
use App\Domains\Accounting\DataTransferObjects\PeriodComparisonLine;
use App\Domains\Accounting\DataTransferObjects\PeriodComparisonResult;
use App\Domains\Accounting\DataTransferObjects\PeriodComparisonTotal;
use App\Domains\Core\Models\Company;
use Illuminate\Support\Collection;

/**
 * Comparativo entre dos periodos indicados (fuera de alcance explícito al
 * cerrar el catálogo de reportes, docs/decisiones.md 2026-08-27 — "se deja
 * para reportes futuros"): reutiliza BalanceSheetService/IncomeStatementService
 * ya probados, construyendo cada estado DOS veces (uno por periodo) y
 * cruzando línea por línea según el código de cuenta — mismo principio de
 * "nunca recalcular, siempre reutilizar el servicio ya validado" que ya usa
 * MultiCompanyComparisonService para comparar entre empresas.
 *
 * A diferencia de MultiCompanyComparisonService (que nunca suma/compara
 * cifras entre compañías porque pueden estar en monedas distintas), acá SÍ
 * tiene sentido calcular variancia: es la misma empresa, la misma moneda, en
 * dos ventanas de tiempo distintas — el propio requisito NIIF de
 * presentación comparativa (CLAUDE.md secc. 7) es justamente esto.
 */
class PeriodComparisonService
{
    public function __construct(
        private readonly BalanceSheetService $balanceSheetService,
        private readonly IncomeStatementService $incomeStatementService,
    ) {}

    public function build(Company $company, string $from1, string $to1, string $from2, string $to2): PeriodComparisonResult
    {
        $bs1 = $this->balanceSheetService->build($company, $to1);
        $bs2 = $this->balanceSheetService->build($company, $to2);
        $is1 = $this->incomeStatementService->build($company, $from1, $to1);
        $is2 = $this->incomeStatementService->build($company, $from2, $to2);

        return new PeriodComparisonResult(
            from1: $from1,
            to1: $to1,
            from2: $from2,
            to2: $to2,
            assets: $this->mergeSection($bs1->assets, $bs2->assets),
            assetsTotal: PeriodComparisonTotal::of($bs1->assetsTotal, $bs2->assetsTotal),
            liabilities: $this->mergeSection($bs1->liabilities, $bs2->liabilities),
            liabilitiesTotal: PeriodComparisonTotal::of($bs1->liabilitiesTotal, $bs2->liabilitiesTotal),
            equity: $this->mergeSection($bs1->equity, $bs2->equity),
            equityTotal: PeriodComparisonTotal::of($bs1->equityTotal, $bs2->equityTotal),
            totalLiabilitiesAndEquity: PeriodComparisonTotal::of($bs1->totalLiabilitiesAndEquity, $bs2->totalLiabilitiesAndEquity),
            sales: $this->mergeSection($is1->sales, $is2->sales),
            salesTotal: PeriodComparisonTotal::of($is1->salesTotal, $is2->salesTotal),
            costOfSales: $this->mergeSection($is1->costOfSales, $is2->costOfSales),
            costOfSalesTotal: PeriodComparisonTotal::of($is1->costOfSalesTotal, $is2->costOfSalesTotal),
            grossProfit: PeriodComparisonTotal::of($is1->grossProfit, $is2->grossProfit),
            operatingExpenses: $this->mergeSection($is1->operatingExpenses, $is2->operatingExpenses),
            operatingExpensesTotal: PeriodComparisonTotal::of($is1->operatingExpensesTotal, $is2->operatingExpensesTotal),
            operatingProfit: PeriodComparisonTotal::of($is1->operatingProfit, $is2->operatingProfit),
            otherIncome: $this->mergeSection($is1->otherIncome, $is2->otherIncome),
            otherIncomeTotal: PeriodComparisonTotal::of($is1->otherIncomeTotal, $is2->otherIncomeTotal),
            otherExpense: $this->mergeSection($is1->otherExpense, $is2->otherExpense),
            otherExpenseTotal: PeriodComparisonTotal::of($is1->otherExpenseTotal, $is2->otherExpenseTotal),
            netProfit: PeriodComparisonTotal::of($is1->netProfit, $is2->netProfit),
        );
    }

    /**
     * Cruza dos secciones (ya con roll-up jerárquico aplicado por
     * AccountRollupBuilder, ver BalanceSheetService/IncomeStatementService)
     * por código de cuenta. Una cuenta que solo tuvo movimiento en un
     * periodo igual aparece en el comparativo, con '0.00' del lado que no
     * tuvo — el propio AccountRollupBuilder ya se encargó de esconder ceros
     * DENTRO de cada periodo (hideZero), acá solo se unen los dos conjuntos.
     *
     * Orden por código de cuenta (string) reproduce el mismo orden
     * padre-antes-que-hijos que ya usan los reportes de un solo periodo,
     * porque los segmentos del código vienen con ancho fijo (ver
     * AccountMaskConfig) — comparación lexicográfica = comparación jerárquica.
     *
     * @param  IncomeStatementLine[]  $lines1
     * @param  IncomeStatementLine[]  $lines2
     * @return PeriodComparisonLine[]
     */
    private function mergeSection(array $lines1, array $lines2): array
    {
        $byCode1 = collect($lines1)->keyBy('code');
        $byCode2 = collect($lines2)->keyBy('code');

        /** @var Collection<int, string> $codes */
        $codes = $byCode1->keys()->merge($byCode2->keys())->unique()->sort()->values();

        return $codes->map(function (string $code) use ($byCode1, $byCode2) {
            $line1 = $byCode1->get($code);
            $line2 = $byCode2->get($code);
            $reference = $line1 ?? $line2;

            return new PeriodComparisonLine(
                code: $code,
                description: $reference->description,
                depth: $reference->depth,
                isHeader: $reference->isHeader,
                amounts: PeriodComparisonTotal::of($line1->amount ?? '0.00', $line2->amount ?? '0.00'),
            );
        })->all();
    }
}
