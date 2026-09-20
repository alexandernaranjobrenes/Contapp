<?php

namespace App\Domains\Inventory\Services;

use App\Domains\BusinessPartners\Support\DayBucketScheme;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\DataTransferObjects\InventoryAgingResult;
use App\Domains\Inventory\DataTransferObjects\InventoryAgingRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Antigüedad de inventario: cuánto valor lleva cuánto tiempo sin rotar.
 *
 * ── Qué mide, y por qué esa definición ───────────────────────────────────
 *
 * La antigüedad se cuenta desde la **última salida**, no desde la última
 * entrada ni desde el último movimiento cualquiera:
 *
 *   - Desde el último movimiento sería engañoso: un artículo al que se le
 *     sigue comprando pero que no se vende nunca aparecería como "recién
 *     movido" justamente cuando es el caso más grave.
 *   - Desde la primera entrada sería incorrecto para un artículo que rota
 *     normalmente: su existencia actual es reciente aunque el artículo lleve
 *     años en el catálogo.
 *
 * Un artículo que NUNCA tuvo salida se cuenta desde su primera entrada —
 * lleva parado desde que llegó— y se marca aparte (`never_issued`), porque
 * es un caso distinto de "rotaba y dejó de rotar" y merece otra decisión.
 *
 * ── Para qué sirve ───────────────────────────────────────────────────────
 *
 * Es el insumo del deterioro de NIC 2 §28 (menor entre costo y valor neto
 * realizable): lo que no rota es lo primero que pierde valor. Este reporte
 * NO calcula ni contabiliza ese deterioro — sigue siendo un proceso
 * periódico manual, como se decidió en la Fase 0 — pero es de donde sale la
 * lista que hay que revisar.
 *
 * El valor se deriva de los movimientos, igual que en
 * InventoryValuationService y por las mismas razones: el promedio de hoy no
 * sirve para valuar un corte pasado, y las columnas `*_after` del kardex son
 * foto de auditoría, no fuente de verdad para un reporte.
 */
class InventoryAgingService
{
    public function build(
        Company $company,
        string $asOf,
        ?string $buckets = null,
        ?int $warehouseId = null,
        ?int $itemGroupId = null,
    ): InventoryAgingResult {
        $scheme = DayBucketScheme::fromInput($buckets, DayBucketScheme::INVENTORY_DEFAULT);
        $labels = $scheme->idleLabels();

        $signedQty = "CASE WHEN stock_journals.direction = 'in' THEN stock_journals.quantity ELSE -stock_journals.quantity END";
        $signedValue = "CASE WHEN stock_journals.direction = 'in' THEN stock_journals.total_cost_local ELSE -stock_journals.total_cost_local END";

        // Una sola consulta agregada: cantidad, valor y las dos fechas que
        // definen la antigüedad, todo por artículo y almacén. Mismo criterio
        // de N+1 que TrialBalanceService e InventoryValuationService.
        $rows = DB::table('stock_journals')
            ->join('items', 'items.id', '=', 'stock_journals.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_journals.warehouse_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->where('stock_journals.company_id', $company->id)
            ->whereDate('stock_journals.posting_date', '<=', $asOf)
            ->when($warehouseId !== null, fn ($q) => $q->where('stock_journals.warehouse_id', $warehouseId))
            ->when($itemGroupId !== null, fn ($q) => $q->where('items.item_group_id', $itemGroupId))
            ->groupBy(
                'stock_journals.item_id', 'stock_journals.warehouse_id',
                'items.code', 'items.name', 'item_groups.name', 'warehouses.code',
            )
            ->orderBy('items.code')
            ->orderBy('warehouses.code')
            ->get([
                'stock_journals.item_id',
                'stock_journals.warehouse_id',
                'items.code as item_code',
                'items.name as item_name',
                'item_groups.name as item_group',
                'warehouses.code as warehouse_code',
                DB::raw("SUM({$signedQty}) as quantity"),
                DB::raw("SUM({$signedValue}) as value_local"),
                DB::raw("MAX(CASE WHEN stock_journals.direction = 'out' THEN stock_journals.posting_date END) as last_issue"),
                DB::raw('MIN(stock_journals.posting_date) as first_movement'),
            ]);

        $cut = Carbon::parse($asOf)->startOfDay();
        $result = [];
        $bucketTotals = array_fill_keys(array_keys($labels), '0.00');
        $total = '0.00';

        foreach ($rows as $row) {
            $quantity = number_format((float) $row->quantity, 6, '.', '');

            // Sin existencia no hay antigüedad que medir: lo que ya salió no
            // es riesgo de obsolescencia. Se descarta acá y no en el SQL
            // porque el saldo es la resta de las dos direcciones.
            if (bccomp($quantity, '0.000000', 6) <= 0) {
                continue;
            }

            $neverIssued = $row->last_issue === null;
            $since = Carbon::parse($neverIssued ? $row->first_movement : $row->last_issue)->startOfDay();

            // diffInDays sin signo: la fecha de referencia siempre es <= corte
            // porque la consulta ya filtró por posting_date <= asOf.
            $daysIdle = (int) $since->diffInDays($cut);
            $bucket = $scheme->resolveIdleKey($daysIdle);
            $value = number_format((float) $row->value_local, 2, '.', '');

            $result[] = new InventoryAgingRow(
                itemId: (int) $row->item_id,
                itemCode: (string) $row->item_code,
                itemName: (string) $row->item_name,
                itemGroup: $row->item_group,
                warehouseId: (int) $row->warehouse_id,
                warehouseCode: (string) $row->warehouse_code,
                quantity: $quantity,
                valueLocal: $value,
                sinceDate: $since->format('Y-m-d'),
                daysIdle: $daysIdle,
                bucket: $bucket,
                neverIssued: $neverIssued,
            );

            $bucketTotals[$bucket] = bcadd($bucketTotals[$bucket], $value, 2);
            $total = bcadd($total, $value, 2);
        }

        // Lo más parado primero: es el orden en que hay que revisarlo.
        usort($result, fn (InventoryAgingRow $a, InventoryAgingRow $b) => $b->daysIdle <=> $a->daysIdle);

        return new InventoryAgingResult(
            asOf: $asOf,
            rows: $result,
            bucketLabels: $labels,
            bucketTotals: $bucketTotals,
            totalValueLocal: $total,
            bucketsInput: $scheme->toInput(),
        );
    }
}
