<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\CostCenterReportGroup;
use App\Domains\Accounting\DataTransferObjects\CostCenterReportLine;
use App\Domains\Accounting\DataTransferObjects\CostCenterReportResult;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Auxiliar por centro de costo (CLAUDE.md secc. 5, "operativos/gestión"):
 * para cada centro de costo, el detalle por cuenta contable de lo que se le
 * imputó en el período — ya sea por asignación directa de la línea o por el
 * reparto de una norma (ambos casos dejan el mismo cost_center_id en
 * journal_details, ver docs/decisiones.md 2026-08-25/2026-08-16). El mayor
 * auxiliar de UN centro (LedgerService dimensión cost-center) ya existía;
 * esto es el resumen de TODOS los centros lado a lado en una sola consulta,
 * sin el patrón N+1 de abrir cada centro por separado.
 */
class CostCenterReportService
{
    public function build(Company $company, ?string $from, ?string $to): CostCenterReportResult
    {
        $periodFrom = $from ?? '0001-01-01';
        $periodTo = $to ?? '9999-12-31';

        $aggregates = DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            // 'voided' cuenta igual que 'posted': ver LedgerService.
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereNotNull('journal_details.cost_center_id')
            ->where('journal_entries.posting_date', '>=', $periodFrom)
            ->where('journal_entries.posting_date', '<=', $periodTo)
            ->groupBy('journal_details.cost_center_id', 'journal_details.account_id')
            ->selectRaw(
                'journal_details.cost_center_id, journal_details.account_id,
                 COALESCE(SUM(journal_details.debit_local), 0) as debit,
                 COALESCE(SUM(journal_details.credit_local), 0) as credit'
            )
            ->get();

        if ($aggregates->isEmpty()) {
            return new CostCenterReportResult($from, $to, [], '0.00', '0.00');
        }

        $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $aggregates->pluck('account_id')->unique())
            ->get()
            ->keyBy('id');

        $costCenters = CostCenter::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $aggregates->pluck('cost_center_id')->unique())
            ->get()
            ->keyBy('id');

        $byCostCenter = $aggregates->groupBy('cost_center_id');

        $groups = $costCenters->sortBy('code')->map(function (CostCenter $costCenter) use ($byCostCenter, $accounts) {
            $lines = $byCostCenter->get($costCenter->id, collect())
                ->map(function ($row) use ($accounts) {
                    $account = $accounts->get($row->account_id);

                    return new CostCenterReportLine(
                        accountId: (int) $row->account_id,
                        accountCode: $account?->code ?? '—',
                        accountDescription: $account?->description_es ?? '—',
                        debit: number_format((float) $row->debit, 2, '.', ''),
                        credit: number_format((float) $row->credit, 2, '.', ''),
                    );
                })
                ->sortBy('accountCode')
                ->values();

            $totalDebit = $lines->reduce(fn ($carry, CostCenterReportLine $l) => bcadd($carry, $l->debit, 2), '0.00');
            $totalCredit = $lines->reduce(fn ($carry, CostCenterReportLine $l) => bcadd($carry, $l->credit, 2), '0.00');

            return new CostCenterReportGroup(
                costCenterId: $costCenter->id,
                costCenterCode: $costCenter->code,
                costCenterName: $costCenter->name,
                lines: $lines->all(),
                totalDebit: $totalDebit,
                totalCredit: $totalCredit,
            );
        })->values();

        $grandTotalDebit = $groups->reduce(fn ($carry, CostCenterReportGroup $g) => bcadd($carry, $g->totalDebit, 2), '0.00');
        $grandTotalCredit = $groups->reduce(fn ($carry, CostCenterReportGroup $g) => bcadd($carry, $g->totalCredit, 2), '0.00');

        return new CostCenterReportResult($from, $to, $groups->all(), $grandTotalDebit, $grandTotalCredit);
    }
}
