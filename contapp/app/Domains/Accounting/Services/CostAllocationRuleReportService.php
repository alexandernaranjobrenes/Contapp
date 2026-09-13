<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\CostAllocationRuleReportGroup;
use App\Domains\Accounting\DataTransferObjects\CostAllocationRuleReportLine;
use App\Domains\Accounting\DataTransferObjects\CostAllocationRuleReportResult;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Por cada norma de reparto, compara cuánto se distribuyó REALMENTE a cada
 * centro de costo en el período (agregado desde journal_details, que ya
 * queda con cost_allocation_rule_id + cost_center_id en cada línea explotada
 * al contabilizar — ver docs/decisiones.md 2026-08-25) contra el porcentaje
 * que la norma define HOY (CostAllocationRuleLine::percentage). Si la norma
 * cambió de definición durante el período, la comparación es contra la
 * definición vigente al momento de generar el reporte, no contra la
 * histórica — una norma es "viva" (edita sus líneas in place), no versionada.
 *
 * El monto real de cada línea es debit_local + credit_local: una línea
 * explotada por reparto nunca tiene los dos lados a la vez (es un asiento
 * normal), así que sumar ambos da la magnitud sin necesitar saber de qué
 * lado cae según la naturaleza de la cuenta.
 */
class CostAllocationRuleReportService
{
    public function build(Company $company, ?string $from, ?string $to): CostAllocationRuleReportResult
    {
        $periodFrom = $from ?? '0001-01-01';
        $periodTo = $to ?? '9999-12-31';

        $aggregates = DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            // 'voided' cuenta igual que 'posted': ver LedgerService.
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereNotNull('journal_details.cost_allocation_rule_id')
            ->where('journal_entries.posting_date', '>=', $periodFrom)
            ->where('journal_entries.posting_date', '<=', $periodTo)
            ->groupBy('journal_details.cost_allocation_rule_id', 'journal_details.cost_center_id')
            ->selectRaw(
                'journal_details.cost_allocation_rule_id, journal_details.cost_center_id,
                 COALESCE(SUM(journal_details.debit_local + journal_details.credit_local), 0) as amount'
            )
            ->get();

        if ($aggregates->isEmpty()) {
            return new CostAllocationRuleReportResult($from, $to, []);
        }

        $rules = CostAllocationRule::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $aggregates->pluck('cost_allocation_rule_id')->unique())
            ->with('lines.costCenter')
            ->get()
            ->sortBy('code');

        $costCenters = CostCenter::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $aggregates->pluck('cost_center_id')->unique())
            ->get()
            ->keyBy('id');

        $byRule = $aggregates->groupBy('cost_allocation_rule_id');

        $groups = $rules->map(function (CostAllocationRule $rule) use ($byRule, $costCenters) {
            $rows = $byRule->get($rule->id, collect());
            $ruleTotal = $rows->reduce(
                fn ($carry, $row) => bcadd($carry, number_format((float) $row->amount, 2, '.', ''), 2),
                '0.00'
            );

            $definedByCostCenter = $rule->lines->keyBy('cost_center_id');
            $actualByCostCenter = $rows->keyBy('cost_center_id');

            // Unión: centros que la norma define HOY + centros que
            // efectivamente recibieron algo en el período (pudo recibir de un
            // centro que ya salió de la definición actual, si la norma cambió).
            $costCenterIds = $definedByCostCenter->keys()->merge($actualByCostCenter->keys())->unique();

            $lines = $costCenterIds->map(function ($costCenterId) use ($definedByCostCenter, $actualByCostCenter, $costCenters, $ruleTotal) {
                $definedLine = $definedByCostCenter->get($costCenterId);
                $actualRow = $actualByCostCenter->get($costCenterId);
                $costCenter = $costCenters->get($costCenterId) ?? $definedLine?->costCenter;

                $actualAmount = $actualRow ? number_format((float) $actualRow->amount, 2, '.', '') : '0.00';
                $definedPercentage = $definedLine ? number_format((float) $definedLine->percentage, 2, '.', '') : '0.00';

                $actualPercentage = bccomp($ruleTotal, '0.00', 2) !== 0
                    ? bcmul(bcdiv($actualAmount, $ruleTotal, 6), '100', 2)
                    : null;

                $variance = $actualPercentage !== null
                    ? bcsub($actualPercentage, $definedPercentage, 2)
                    : null;

                return new CostAllocationRuleReportLine(
                    costCenterId: (int) $costCenterId,
                    costCenterCode: $costCenter?->code ?? '—',
                    costCenterName: $costCenter?->name ?? '—',
                    definedPercentage: $definedPercentage,
                    actualAmount: $actualAmount,
                    actualPercentage: $actualPercentage,
                    variancePercentagePoints: $variance,
                );
            })->sortBy('costCenterCode')->values();

            return new CostAllocationRuleReportGroup(
                ruleId: $rule->id,
                ruleCode: $rule->code,
                ruleName: $rule->name,
                lines: $lines->all(),
                totalAmount: $ruleTotal,
            );
        })->values();

        return new CostAllocationRuleReportResult($from, $to, $groups->all());
    }
}
