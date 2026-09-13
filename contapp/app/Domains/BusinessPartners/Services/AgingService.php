<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\DataTransferObjects\AgingCurrencyGroup;
use App\Domains\BusinessPartners\DataTransferObjects\AgingDocumentLine;
use App\Domains\BusinessPartners\DataTransferObjects\AgingResult;
use App\Domains\BusinessPartners\DataTransferObjects\AgingRow;
use App\Domains\BusinessPartners\Support\DayBucketScheme;
use App\Domains\Core\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Antigüedad de saldos: agrupa las partidas abiertas (bp_open_items, ver
 * ApplyPaymentService) de clientes/proveedores en buckets por días vencidos
 * a una fecha de corte. `bp_open_items.balance` ya es un saldo mantenido
 * (se actualiza en cada aplicación de pago) — a diferencia de los reportes
 * contables de este módulo, este SÍ es un dato legítimamente almacenado
 * (es el saldo pendiente de un documento puntual, no un saldo de cuenta
 * contable general), consistente con el diseño ya establecido de
 * BpOpenItem/ApplyPaymentService.
 *
 * Los cortes de días (30/60/90 por defecto) son configurables por el usuario
 * (ver DayBucketScheme) — las columnas del reporte se generan dinámicamente
 * a partir de esos cortes, no hay buckets fijos en código.
 *
 * Agrupado por moneda (no un solo total mezclado): dos partidas en monedas
 * distintas no se pueden sumar en una sola cifra sin convertir, y este
 * reporte no asume una tasa de conversión — cada grupo de moneda trae su
 * propio total, coherente en sí mismo.
 */
class AgingService
{
    public function build(Company $company, string $asOf, string $partnerType = 'both', ?string $buckets = null): AgingResult
    {
        $scheme = DayBucketScheme::fromInput($buckets);
        $bucketLabels = $scheme->overdueLabels();

        $rows = DB::table('bp_open_items')
            ->join('business_partners', 'business_partners.id', '=', 'bp_open_items.business_partner_id')
            ->join('currencies', 'currencies.id', '=', 'bp_open_items.currency_id')
            ->where('business_partners.company_id', $company->id)
            ->where('bp_open_items.status', '!=', 'closed')
            ->when($partnerType !== 'both', fn ($q) => $q->where(function ($q2) use ($partnerType) {
                $q2->where('business_partners.type', $partnerType)->orWhere('business_partners.type', 'both');
            }))
            ->select(
                'business_partners.code as partner_code',
                'business_partners.name as partner_name',
                'currencies.code as currency_code',
                'bp_open_items.document_type_code',
                'bp_open_items.document_number',
                'bp_open_items.due_date',
                'bp_open_items.original_amount',
                'bp_open_items.balance',
            )
            ->orderBy('bp_open_items.due_date')
            ->get();

        $asOfDate = Carbon::parse($asOf);

        // currency_code => partner_code => ['name' => ..., 'buckets' => [...], 'documents' => [...]]
        $byCurrency = [];

        foreach ($rows as $row) {
            $bucketKey = $scheme->resolveOverdueKey(Carbon::parse($row->due_date ?? $asOf)->diffInDays($asOfDate, false));

            $byCurrency[$row->currency_code][$row->partner_code] ??= [
                'name' => $row->partner_name,
                'buckets' => array_fill_keys(array_keys($bucketLabels), '0.00'),
                'documents' => [],
            ];

            $byCurrency[$row->currency_code][$row->partner_code]['buckets'][$bucketKey] = bcadd(
                $byCurrency[$row->currency_code][$row->partner_code]['buckets'][$bucketKey],
                (string) $row->balance,
                2
            );

            // Detalle por documento (número/fecha/monto), lo que despliega la
            // pantalla al expandir un socio — cada partida abierta detrás del
            // total agregado de la fila.
            $byCurrency[$row->currency_code][$row->partner_code]['documents'][] = new AgingDocumentLine(
                documentLabel: $row->document_type_code && $row->document_number
                    ? "{$row->document_type_code}-{$row->document_number}"
                    : ($row->document_type_code ?? '—'),
                dueDate: $row->due_date ? Carbon::parse($row->due_date)->format('Y-m-d') : null,
                originalAmount: bcadd((string) $row->original_amount, '0', 2),
                balance: bcadd((string) $row->balance, '0', 2),
                bucket: $bucketKey,
            );
        }

        $groups = [];
        foreach ($byCurrency as $currencyCode => $partners) {
            $groups[] = $this->buildGroup($currencyCode, $partners, $bucketLabels);
        }

        return new AgingResult($asOf, $partnerType, $bucketLabels, $groups);
    }

    /**
     * @param  array<string, string>  $bucketLabels
     */
    private function buildGroup(string $currencyCode, array $partners, array $bucketLabels): AgingCurrencyGroup
    {
        $rows = [];
        $totals = array_fill_keys(array_keys($bucketLabels), '0.00');

        ksort($partners);

        foreach ($partners as $partnerCode => $data) {
            $total = '0.00';
            foreach (array_keys($bucketLabels) as $bucketKey) {
                $total = bcadd($total, $data['buckets'][$bucketKey], 2);
                $totals[$bucketKey] = bcadd($totals[$bucketKey], $data['buckets'][$bucketKey], 2);
            }

            $rows[] = new AgingRow(
                partnerCode: $partnerCode,
                partnerName: $data['name'],
                buckets: $data['buckets'],
                total: $total,
                documents: $data['documents'],
            );
        }

        $grandTotal = array_reduce($totals, fn ($carry, $value) => bcadd($carry, $value, 2), '0.00');

        return new AgingCurrencyGroup(
            currencyCode: $currencyCode,
            rows: $rows,
            bucketTotals: $totals,
            total: $grandTotal,
        );
    }
}
