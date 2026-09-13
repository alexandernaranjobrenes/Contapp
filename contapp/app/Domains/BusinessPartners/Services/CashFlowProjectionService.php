<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\DataTransferObjects\CashFlowProjectionResult;
use App\Domains\BusinessPartners\DataTransferObjects\ProjectionCurrencyGroup;
use App\Domains\BusinessPartners\DataTransferObjects\ProjectionDocumentLine;
use App\Domains\BusinessPartners\DataTransferObjects\ProjectionRow;
use App\Domains\BusinessPartners\Support\DayBucketScheme;
use App\Domains\Core\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proyección de cobros y pagos: mismas partidas abiertas que AgingService,
 * pero mirando hacia adelante desde la fecha de corte (¿cuándo vence?, no
 * ¿hace cuánto venció?) y separadas en dos flujos — cobros esperados de
 * clientes, pagos esperados a proveedores.
 *
 * La dirección (cobro vs. pago) se determina por la naturaleza de la cuenta
 * contable de origen de la partida (normal_balance de la cuenta a la que se
 * contabilizó, vía journal_details.account_id) y NO por `business_partners.type`:
 * un socio marcado "both" puede tener partidas de ambos tipos, y el tipo de
 * cuenta contable es la fuente de verdad real de si algo es una cuenta por
 * cobrar (deudora) o por pagar (acreedora), no una etiqueta del socio.
 *
 * Los cortes de días (15/30/60/90 por defecto) son configurables por el
 * usuario (ver DayBucketScheme) — las columnas se generan dinámicamente.
 */
class CashFlowProjectionService
{
    public function build(Company $company, string $asOf, ?string $buckets = null): CashFlowProjectionResult
    {
        $scheme = DayBucketScheme::fromInput($buckets, DayBucketScheme::CASH_FLOW_DEFAULT);
        $bucketLabels = $scheme->untilDueLabels();

        $rows = DB::table('bp_open_items')
            ->join('business_partners', 'business_partners.id', '=', 'bp_open_items.business_partner_id')
            ->join('currencies', 'currencies.id', '=', 'bp_open_items.currency_id')
            ->join('journal_details', 'journal_details.id', '=', 'bp_open_items.origin_journal_detail_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_details.account_id')
            ->where('business_partners.company_id', $company->id)
            ->where('bp_open_items.status', '!=', 'closed')
            ->select(
                'business_partners.code as partner_code',
                'business_partners.name as partner_name',
                'currencies.code as currency_code',
                'chart_of_accounts.normal_balance',
                'bp_open_items.document_type_code',
                'bp_open_items.document_number',
                'bp_open_items.due_date',
                'bp_open_items.original_amount',
                'bp_open_items.balance',
            )
            ->orderBy('bp_open_items.due_date')
            ->get();

        $asOfDate = Carbon::parse($asOf);

        $byDirection = ['collections' => [], 'payments' => []];

        foreach ($rows as $row) {
            $direction = $row->normal_balance === 'debit' ? 'collections' : 'payments';
            $bucketKey = $scheme->resolveUntilDueKey($asOfDate->diffInDays(Carbon::parse($row->due_date ?? $asOf), false));

            $byDirection[$direction][$row->currency_code][$row->partner_code] ??= [
                'name' => $row->partner_name,
                'buckets' => array_fill_keys(array_keys($bucketLabels), '0.00'),
                'documents' => [],
            ];

            $byDirection[$direction][$row->currency_code][$row->partner_code]['buckets'][$bucketKey] = bcadd(
                $byDirection[$direction][$row->currency_code][$row->partner_code]['buckets'][$bucketKey],
                (string) $row->balance,
                2
            );

            $byDirection[$direction][$row->currency_code][$row->partner_code]['documents'][] = new ProjectionDocumentLine(
                documentLabel: $row->document_type_code && $row->document_number
                    ? "{$row->document_type_code}-{$row->document_number}"
                    : ($row->document_type_code ?? '—'),
                dueDate: $row->due_date ? Carbon::parse($row->due_date)->format('Y-m-d') : null,
                originalAmount: bcadd((string) $row->original_amount, '0', 2),
                balance: bcadd((string) $row->balance, '0', 2),
                bucket: $bucketKey,
            );
        }

        return new CashFlowProjectionResult(
            asOf: $asOf,
            bucketLabels: $bucketLabels,
            collections: $this->buildGroups($byDirection['collections'], $bucketLabels),
            payments: $this->buildGroups($byDirection['payments'], $bucketLabels),
        );
    }

    /**
     * @param  array<string, string>  $bucketLabels
     * @return ProjectionCurrencyGroup[]
     */
    private function buildGroups(array $byCurrency, array $bucketLabels): array
    {
        $groups = [];

        foreach ($byCurrency as $currencyCode => $partners) {
            $rows = [];
            $totals = array_fill_keys(array_keys($bucketLabels), '0.00');

            ksort($partners);

            foreach ($partners as $partnerCode => $data) {
                $total = '0.00';
                foreach (array_keys($bucketLabels) as $bucketKey) {
                    $total = bcadd($total, $data['buckets'][$bucketKey], 2);
                    $totals[$bucketKey] = bcadd($totals[$bucketKey], $data['buckets'][$bucketKey], 2);
                }

                $rows[] = new ProjectionRow(
                    partnerCode: $partnerCode,
                    partnerName: $data['name'],
                    buckets: $data['buckets'],
                    total: $total,
                    documents: $data['documents'],
                );
            }

            $grandTotal = array_reduce($totals, fn ($carry, $value) => bcadd($carry, $value, 2), '0.00');

            $groups[] = new ProjectionCurrencyGroup(
                currencyCode: $currencyCode,
                rows: $rows,
                bucketTotals: $totals,
                total: $grandTotal,
            );
        }

        return $groups;
    }
}
