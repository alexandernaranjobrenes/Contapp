<?php

namespace App\Http\Controllers;

use App\Domains\Inventory\Models\ItemLot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Próximos a vencer: los lotes CON SALDO ordenados por cercanía de
 * vencimiento. Es el reporte operativo que justifica manejar fechas de
 * vencimiento — sin él, la fecha es un dato que nadie mira hasta que la
 * mercancía ya se perdió.
 *
 * Sustento contable: NIC 2 §28 obliga a valuar al menor entre costo y valor
 * neto realizable, y la obsolescencia por vencimiento es la causa típica de
 * que el VNR caiga por debajo del costo. Este reporte NO calcula el
 * deterioro ni lo contabiliza —eso sigue siendo un proceso periódico manual,
 * como se decidió en la Fase 7— pero es el insumo para decidirlo.
 */
class LotExpiryController extends Controller
{
    /** Cortes en días, del más urgente al más holgado. */
    private const BUCKETS = [
        ['key' => 'expired', 'label' => 'Vencidos', 'from' => null, 'to' => -1],
        ['key' => 'd15', 'label' => 'Vencen en 15 días', 'from' => 0, 'to' => 15],
        ['key' => 'd30', 'label' => 'Vencen en 30 días', 'from' => 16, 'to' => 30],
        ['key' => 'd60', 'label' => 'Vencen en 60 días', 'from' => 31, 'to' => 60],
        ['key' => 'd90', 'label' => 'Vencen en 90 días', 'from' => 61, 'to' => 90],
        ['key' => 'later', 'label' => 'Más de 90 días', 'from' => 91, 'to' => null],
    ];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        // El horizonte es un parámetro, no un condicional fijo: el checklist
        // de CLAUDE.md secc. 9 pide justamente eso de todo reporte.
        $days = (int) ($validated['days'] ?? 90);
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
        $today = now()->startOfDay();

        $lots = ItemLot::query()
            // Solo lotes CON SALDO: un lote agotado ya no es un riesgo de
            // obsolescencia, aunque su fecha haya pasado.
            ->whereHas('stockLevels', function ($query) use ($warehouseId) {
                $query->where('on_hand', '>', 0)
                    ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId));
            })
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays($days)->format('Y-m-d'))
            // whereHas en item: el CompanyScope de Item es lo que aísla la
            // compañía, porque item_lots no lleva company_id propio.
            ->whereHas('item')
            ->with([
                'item:id,code,name',
                'stockLevels' => fn ($q) => $q->where('on_hand', '>', 0)
                    ->when($warehouseId !== null, fn ($qq) => $qq->where('warehouse_id', $warehouseId))
                    ->with('warehouse:id,code'),
            ])
            ->fefo()
            ->get();

        $rows = $lots->map(function (ItemLot $lot) use ($today) {
            $daysLeft = (int) $today->diffInDays($lot->expires_at, false);

            return [
                'id' => $lot->id,
                'item_id' => $lot->item_id,
                'item_code' => $lot->item?->code,
                'item_name' => $lot->item?->name,
                'code' => $lot->code,
                'expires_at' => $lot->expires_at->format('Y-m-d'),
                'days_left' => $daysLeft,
                'bucket' => $this->bucketFor($daysLeft),
                'status' => $lot->status,
                'on_hand' => (float) $lot->stockLevels->sum('on_hand'),
                'warehouses' => $lot->stockLevels
                    ->map(fn ($s) => $s->warehouse?->code)
                    ->filter()->unique()->values()->all(),
            ];
        });

        return Inertia::render('Inventory/Lots/Expiry', [
            'rows' => $rows,
            'buckets' => self::BUCKETS,
            'summary' => collect(self::BUCKETS)
                ->mapWithKeys(fn (array $b) => [
                    $b['key'] => $rows->where('bucket', $b['key'])->count(),
                ]),
            'filters' => ['days' => $days, 'warehouse_id' => $warehouseId],
            'warehouses' => \App\Domains\Inventory\Models\Warehouse::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    private function bucketFor(int $daysLeft): string
    {
        foreach (self::BUCKETS as $bucket) {
            $afterFrom = $bucket['from'] === null || $daysLeft >= $bucket['from'];
            $beforeTo = $bucket['to'] === null || $daysLeft <= $bucket['to'];

            if ($afterFrom && $beforeTo) {
                return $bucket['key'];
            }
        }

        return 'later';
    }
}
