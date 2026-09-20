<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidStockCountException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\StockCount;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Toma física de inventario: abrir el conteo, capturar lo contado y cerrarlo
 * generando el ajuste.
 *
 * El ajuste NO se escribe acá. Lo genera PostStockMovementService con la
 * operación count_adjustment, que ya existía y sigue siendo el único dueño
 * del kardex; este service solo decide QUÉ contar, congela contra qué
 * comparar, y traduce lo contado a líneas de ajuste.
 *
 * La existencia teórica se calcula del KARDEX a la fecha de corte, no de
 * item_warehouses.on_hand: on_hand es "ahora", y una hoja de conteo con
 * corte al viernes tiene que decir lo que había el viernes.
 */
class StockCountService
{
    public function __construct(private readonly PostStockMovementService $postStockMovementService) {}

    /**
     * Abre la toma física: congela la existencia teórica al corte y arma una
     * línea por cada combinación que haya que contar.
     *
     * Se incluyen también los artículos con existencia CERO a la fecha de
     * corte —siempre que hayan tenido movimiento alguna vez en ese almacén—
     * porque encontrar mercancía donde el sistema dice que no hay es
     * justamente uno de los hallazgos que una toma física busca.
     */
    public function open(
        Company $company,
        DocumentType $documentType,
        \DateTimeInterface $cutoffDate,
        int $warehouseId,
        ?int $itemGroupId = null,
        bool $blind = false,
        ?string $description = null,
        ?int $createdBy = null,
    ): StockCount {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        return DB::transaction(function () use ($company, $documentType, $cutoffDate, $warehouseId, $itemGroupId, $blind, $description, $createdBy) {
            $warehouse = Warehouse::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($warehouseId);

            if (! $warehouse) {
                throw new InvalidStockCountException("El almacén id {$warehouseId} no existe en la compañía.");
            }

            if ($warehouse->status !== 'active') {
                throw new InvalidStockCountException("El almacén {$warehouse->code} está inactivo; no admite conteos.");
            }

            // Una toma física abierta sobre el mismo almacén bloquearía a la
            // otra al cerrar: las dos ajustarían contra la misma existencia.
            $abierta = StockCount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 'open')
                ->first();

            if ($abierta) {
                throw new InvalidStockCountException(
                    "Ya hay una toma física abierta para {$warehouse->code} (la #{$abierta->number}); ".
                    'cerrala o cancelala antes de abrir otra.'
                );
            }

            $items = $this->itemsToCount($company, $itemGroupId);

            if ($items->isEmpty()) {
                throw new InvalidStockCountException(
                    'No hay artículos de inventario que contar con esos parámetros.'
                );
            }

            $theoretical = $this->theoreticalAtCutoff($company, $warehouse->id, $cutoffDate, $items->keys()->all());

            if (empty($theoretical)) {
                throw new InvalidStockCountException(
                    "El almacén {$warehouse->code} no tiene movimientos registrados a esa fecha de corte; no hay nada que contar."
                );
            }

            $count = StockCount::create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'document_type_id' => $documentType->id,
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'warehouse_id' => $warehouse->id,
                'item_group_id' => $itemGroupId,
                'blind' => $blind,
                'status' => 'open',
                'description' => $description,
                'created_by' => $createdBy,
            ]);

            $lineNumber = 0;

            foreach ($theoretical as $key => $quantity) {
                [$itemId, $binId] = array_pad(explode(':', (string) $key), 2, null);
                $item = $items->get((int) $itemId);

                if (! $item) {
                    continue;
                }

                $count->lines()->create([
                    'line_number' => ++$lineNumber,
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'warehouse_bin_id' => $binId ? (int) $binId : null,
                    'theoretical_quantity' => $quantity,
                    'counted_quantity' => null,
                    'unit_cost_local' => $item->avg_cost_local,
                ]);
            }

            return $count->load('lines');
        });
    }

    /**
     * Registra lo contado. Se puede llamar varias veces —el conteo se captura
     * por tandas— y una línea sin dato sigue pendiente.
     *
     * @param  array<int, int|float|string|null>  $counted  [id de línea => cantidad contada]
     */
    public function capture(Company $company, StockCount $count, array $counted): StockCount
    {
        return DB::transaction(function () use ($company, $count, $counted) {
            $count = $this->lockOpen($company, $count);
            $lines = $count->lines()->get()->keyBy('id');

            foreach ($counted as $lineId => $quantity) {
                $line = $lines->get((int) $lineId);

                if (! $line) {
                    throw new InvalidStockCountException("La línea id {$lineId} no pertenece a esta toma física.");
                }

                if ($quantity === null || $quantity === '') {
                    $line->update(['counted_quantity' => null]);

                    continue;
                }

                if (bccomp(number_format((float) $quantity, 6, '.', ''), '0.000000', 6) < 0) {
                    throw new InvalidStockCountException(
                        'Una cantidad contada no puede ser negativa: contar es decir cuánto hay, y lo mínimo que puede haber es cero.'
                    );
                }

                $line->update(['counted_quantity' => number_format((float) $quantity, 6, '.', '')]);
            }

            return $count->fresh('lines');
        });
    }

    /**
     * Cierra la toma física y contabiliza el ajuste. Solo las líneas con
     * diferencia mueven algo; si no hubo ninguna, la toma se cierra igual y
     * queda como constancia de que se contó y todo cuadraba.
     */
    public function post(
        Company $company,
        StockCount $count,
        \DateTimeInterface $postingDate,
        ?int $postedBy = null,
    ): StockCount {
        return DB::transaction(function () use ($company, $count, $postingDate, $postedBy) {
            $count = $this->lockOpen($company, $count);
            $lines = $count->lines()->with('item:id,code,name')->get();

            $sinContar = $lines->filter(fn ($line) => ! $line->isCounted());

            if ($sinContar->isNotEmpty()) {
                throw new InvalidStockCountException(
                    "Quedan {$sinContar->count()} línea(s) sin contar; una toma física se cierra completa. ".
                    'Si un artículo no apareció, se cuenta en cero.'
                );
            }

            $this->assertStockUnchangedSinceCutoff($company, $count, $lines);

            // count_adjustment espera la cantidad CONTADA, no la diferencia:
            // el motor calcula el delta contra la existencia y decide si es
            // entrada o salida. Las líneas sin diferencia se omiten porque el
            // motor las descartaría igual.
            $adjustmentLines = [];

            foreach ($lines as $line) {
                if (bccomp($line->difference(), '0.000000', 6) === 0) {
                    continue;
                }

                $adjustmentLines[] = new StockLineInput(
                    itemId: $line->item_id,
                    warehouseId: $line->warehouse_id,
                    quantity: $line->counted_quantity,
                    description: "Toma física #{$count->number}",
                    warehouseBinId: $line->warehouse_bin_id,
                );
            }

            $document = null;

            if (! empty($adjustmentLines)) {
                $document = $this->postStockMovementService->post(
                    company: $company,
                    documentType: $count->documentType()->withoutGlobalScope(CompanyScope::class)->firstOrFail(),
                    operation: 'count_adjustment',
                    documentDate: $count->cutoff_date,
                    postingDate: $postingDate,
                    lines: $adjustmentLines,
                    description: $count->description ?? "Ajuste por toma física #{$count->number}",
                    createdBy: $postedBy,
                );
            }

            $count->update([
                'status' => 'posted',
                'inventory_document_id' => $document?->id,
                'posted_by' => $postedBy,
                'posted_at' => now(),
            ]);

            return $count->fresh('lines');
        });
    }

    public function cancel(Company $company, StockCount $count, ?string $reason = null): StockCount
    {
        return DB::transaction(function () use ($company, $count, $reason) {
            $count = $this->lockOpen($company, $count);

            $count->update([
                'status' => 'cancelled',
                'description' => $reason ?? $count->description,
            ]);

            return $count->fresh('lines');
        });
    }

    /**
     * Entre el corte y el cierre no puede haberse movido nada: el ajuste se
     * calcula contra la existencia de HOY, así que si algo entró o salió
     * después del corte, aplicar lo contado borraría ese movimiento.
     *
     * Es la razón por la que un almacén se congela mientras se cuenta. Cuando
     * esto salta, lo correcto es volver a abrir la toma con un corte nuevo.
     *
     * @param  Collection  $lines
     */
    private function assertStockUnchangedSinceCutoff(Company $company, StockCount $count, $lines): void
    {
        $actual = $this->currentQuantities($company, $count->warehouse_id, $lines);

        foreach ($lines as $line) {
            $key = $line->item_id.($line->warehouse_bin_id ? ':'.$line->warehouse_bin_id : '');
            $hoy = $actual[$key] ?? '0.000000';

            if (bccomp($hoy, (string) $line->theoretical_quantity, 6) !== 0) {
                throw new InvalidStockCountException(
                    "La existencia de {$line->item->code} cambió después de la fecha de corte ".
                    '(la hoja decía '.$this->trim((string) $line->theoretical_quantity).
                    ' y hoy hay '.$this->trim($hoy).'): la toma quedó desactualizada. '.
                    'Abrí una nueva con un corte al día de hoy.'
                );
            }
        }
    }

    /**
     * Existencia actual de las mismas combinaciones que se contaron.
     *
     * @return array<string, string>
     */
    private function currentQuantities(Company $company, int $warehouseId, $lines): array
    {
        $itemIds = $lines->pluck('item_id')->unique()->values()->all();
        $usaUbicaciones = $lines->contains(fn ($line) => $line->warehouse_bin_id !== null);

        $actual = [];

        if ($usaUbicaciones) {
            foreach (ItemBin::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $actual[$row->item_id.':'.$row->warehouse_bin_id] = (string) $row->on_hand;
            }

            return $actual;
        }

        foreach (ItemWarehouse::whereIn('item_id', $itemIds)
            ->where('warehouse_id', $warehouseId)->lockForUpdate()->get() as $row) {
            $actual[$row->item_id] = (string) $row->on_hand;
        }

        return $actual;
    }

    /**
     * Artículos candidatos a contarse: los que llevan kardex, filtrados por
     * familia si se indicó una.
     */
    private function itemsToCount(Company $company, ?int $itemGroupId)
    {
        return Item::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_inventory_item', true)
            ->when($itemGroupId, fn ($q) => $q->where('item_group_id', $itemGroupId))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'avg_cost_local', 'item_group_id'])
            ->keyBy('id');
    }

    /**
     * Existencia según el kardex a la fecha de corte, por artículo y —si el
     * almacén usa ubicaciones— por ubicación.
     *
     * Se suma el kardex en vez de leer item_warehouses porque esa tabla dice
     * "ahora": la hoja de conteo tiene que decir lo que había al corte.
     * Las filas de revaluación (landed cost, diferencia de precio) no mueven
     * unidades y por eso no entran en la suma.
     *
     * @param  int[]  $itemIds
     * @return array<string, string>
     */
    private function theoreticalAtCutoff(Company $company, int $warehouseId, \DateTimeInterface $cutoff, array $itemIds): array
    {
        $rows = StockJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->whereDate('posting_date', '<=', $cutoff->format('Y-m-d'))
            ->whereIn('direction', ['in', 'out'])
            ->get(['item_id', 'warehouse_bin_id', 'direction', 'quantity']);

        $totals = [];

        foreach ($rows as $row) {
            $key = $row->item_id.($row->warehouse_bin_id ? ':'.$row->warehouse_bin_id : '');
            $signed = $row->direction === 'in' ? (string) $row->quantity : bcmul((string) $row->quantity, '-1', 6);

            $totals[$key] = bcadd($totals[$key] ?? '0.000000', $signed, 6);
        }

        ksort($totals);

        return $totals;
    }

    private function lockOpen(Company $company, StockCount $count): StockCount
    {
        $fresh = StockCount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->lockForUpdate()
            ->findOrFail($count->id);

        if ($fresh->status !== 'open') {
            throw new InvalidStockCountException(
                'La toma física ya fue '.($fresh->status === 'posted' ? 'cerrada' : 'cancelada').'.'
            );
        }

        return $fresh;
    }

    private function nextNumber(Company $company): string
    {
        $last = StockCount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->lockForUpdate()
            ->max('number');

        return str_pad((string) (((int) $last) + 1), 8, '0', STR_PAD_LEFT);
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
