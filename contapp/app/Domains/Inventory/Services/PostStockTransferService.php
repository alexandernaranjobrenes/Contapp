<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\StockTransferLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use Illuminate\Support\Facades\DB;

/**
 * Traslado de mercancía entre almacenes (o entre ubicaciones del mismo).
 *
 * Vive aparte de PostStockMovementService y no dentro porque su forma es otra:
 * una línea produce DOS filas de kardex —una salida del origen y una entrada
 * al destino— mientras que todo el resto del motor asume "una línea, un
 * movimiento, un almacén". Meterlo ahí habría llenado de condicionales el
 * service del que dependen inventario, producción y facturación.
 *
 * Lo que un traslado NO hace, y es lo que lo vuelve simple: no toca el costo
 * promedio. El costeo de este proyecto es global por artículo, así que mover
 * una unidad de estante cambia dónde está, no cuánto vale.
 *
 * Y por eso mismo puede no generar asiento. Ver la migración
 * 2026_09_15_140000: la invariante del módulo es "todo cambio en el VALOR del
 * inventario tiene su asiento", no "todo documento tiene asiento".
 */
class PostStockTransferService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
        private readonly WarehouseBinResolver $binResolver,
    ) {}

    /**
     * @param  StockTransferLineInput[]  $lines
     */
    public function post(
        Company $company,
        DocumentType $documentType,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryDocument {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Un traslado requiere al menos una línea.');
        }

        return DB::transaction(function () use ($company, $documentType, $documentDate, $postingDate, $lines, $description, $createdBy) {
            $itemIds = array_values(array_unique(array_map(fn (StockTransferLineInput $l) => $l->itemId, $lines)));

            $warehouseIds = array_values(array_unique(array_merge(
                array_map(fn (StockTransferLineInput $l) => $l->fromWarehouseId, $lines),
                array_map(fn (StockTransferLineInput $l) => $l->toWarehouseId, $lines),
            )));

            // Mismo lock ordenado por id que el motor de movimientos: sin él,
            // un traslado y una salida concurrentes sobre el mismo artículo
            // pueden pasar ambos la validación de existencia.
            $items = Item::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $warehouses = Warehouse::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', $warehouseIds)
                ->get()
                ->keyBy('id');

            $onHand = [];
            foreach (ItemWarehouse::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $onHand[$row->item_id][$row->warehouse_id] = (string) $row->on_hand;
            }

            $bins = WarehouseBin::whereIn('warehouse_id', $warehouseIds)->get()->keyBy('id');

            $binOnHand = [];
            foreach (ItemBin::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $binOnHand[$row->item_id][$row->warehouse_bin_id] = (string) $row->on_hand;
            }

            $rules = $this->glResolver->load($company);

            $movements = [];
            $journalLines = [];

            foreach (array_values($lines) as $line) {
                $item = $items->get($line->itemId)
                    ?? throw new InvalidStockMovementException("El artículo id {$line->itemId} no existe en la compañía.");

                if (! $item->is_inventory_item) {
                    throw new InvalidStockMovementException(
                        "El artículo {$item->code} está marcado como servicio; no lleva kardex y no puede trasladarse."
                    );
                }

                $from = $this->activeWarehouse($warehouses, $line->fromWarehouseId);
                $to = $this->activeWarehouse($warehouses, $line->toWarehouseId);

                $fromBin = $this->binResolver->resolve($from, $line->fromWarehouseBinId, $bins);
                $toBin = $this->binResolver->resolve($to, $line->toWarehouseBinId, $bins);

                // Trasladar a donde ya está no mueve nada. Con ubicaciones,
                // origen y destino pueden ser el mismo almacén siempre que la
                // ubicación cambie: eso sí es un movimiento real.
                if ($from->id === $to->id && $fromBin?->id === $toBin?->id) {
                    throw new InvalidStockMovementException(
                        "El traslado del artículo {$item->code} tiene el mismo origen y destino."
                    );
                }

                $available = $fromBin
                    ? ($binOnHand[$item->id][$fromBin->id] ?? '0.000000')
                    : ($onHand[$item->id][$from->id] ?? '0.000000');

                if (bccomp($line->quantity, $available, 6) > 0) {
                    throw new InsufficientStockException(
                        "No hay existencia suficiente de {$item->code} en el almacén {$from->code}: ".
                        'se piden '.$this->trim($line->quantity).' y hay '.$this->trim($available).'.'
                    );
                }

                // El valor trasladado se calcula al promedio vigente y NO lo
                // modifica: es el mismo inventario, en otro lugar.
                $unitLocal = (string) $item->avg_cost_local;
                $unitForeign = (string) $item->avg_cost_foreign;
                $totalLocal = $this->money(bcmul($line->quantity, $unitLocal, 12));

                // Secuencial y no en paralelo: en un traslado entre ubicaciones
                // del MISMO almacén, calcular ambos lados desde el valor
                // inicial haría que la suma pise a la resta e inflara la
                // existencia por la cantidad trasladada.
                $onHand[$item->id][$from->id] = bcsub($onHand[$item->id][$from->id] ?? '0.000000', $line->quantity, 6);
                $fromQty = $onHand[$item->id][$from->id];

                $onHand[$item->id][$to->id] = bcadd($onHand[$item->id][$to->id] ?? '0.000000', $line->quantity, 6);
                $toQty = $onHand[$item->id][$to->id];

                if ($fromBin) {
                    $binOnHand[$item->id][$fromBin->id] = bcsub(
                        $binOnHand[$item->id][$fromBin->id] ?? '0.000000', $line->quantity, 6
                    );
                }

                if ($toBin) {
                    $binOnHand[$item->id][$toBin->id] = bcadd(
                        $binOnHand[$item->id][$toBin->id] ?? '0.000000', $line->quantity, 6
                    );
                }

                $movements[] = compact('line', 'item', 'from', 'to', 'fromBin', 'toBin')
                    + ['unit_local' => $unitLocal, 'unit_foreign' => $unitForeign,
                        'total_local' => $totalLocal, 'from_balance' => $fromQty, 'to_balance' => $toQty];

                $sourceAccount = $this->glResolver->resolve($rules, 'inventory', $item, $from, $documentType, 'credit');
                $destinationAccount = $this->glResolver->resolve($rules, 'inventory', $item, $to, $documentType, 'debit');

                // Si ambos almacenes resuelven a la misma cuenta Y la misma
                // norma de reparto, no hay nada que reclasificar: el asiento
                // sería Debe X / Haber X por el mismo monto. Ver la migración
                // 2026_09_15_140000 para el razonamiento completo.
                if ($this->sameAccounting($sourceAccount, $destinationAccount)) {
                    continue;
                }

                if (bccomp($totalLocal, '0.00', 2) <= 0) {
                    throw new InvalidStockMovementException(
                        "El artículo {$item->code} no tiene costo promedio registrado; ".
                        'un traslado entre cuentas distintas no puede reclasificar un valor de cero.'
                    );
                }

                $rate = bcdiv($unitLocal, $unitForeign, 6);
                $label = $line->description ?? "{$item->code}: {$from->code} → {$to->code}";

                $journalLines[] = new JournalLineInput(
                    accountId: $destinationAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $totalLocal,
                    credit: 0,
                    description: $label,
                    costAllocationRuleId: $destinationAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $rate,
                );

                $journalLines[] = new JournalLineInput(
                    accountId: $sourceAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: 0,
                    credit: $totalLocal,
                    description: $label,
                    costAllocationRuleId: $sourceAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $rate,
                );
            }

            $entry = empty($journalLines) ? null : $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $journalLines,
                description: $description ?? 'Traslado entre almacenes',
                createdBy: $createdBy,
            );

            $document = InventoryDocument::create([
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'journal_entry_id' => $entry?->id,
                'operation' => 'transfer',
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'description' => $description,
                'status' => 'posted',
                'created_by' => $createdBy,
            ]);

            foreach ($movements as $position => $movement) {
                $documentLine = $document->lines()->create([
                    'line_number' => $position + 1,
                    'item_id' => $movement['item']->id,
                    'warehouse_id' => $movement['from']->id,
                    'warehouse_bin_id' => $movement['fromBin']?->id,
                    'to_warehouse_id' => $movement['to']->id,
                    'to_warehouse_bin_id' => $movement['toBin']?->id,
                    'quantity' => $movement['line']->quantity,
                    'unit_cost_local' => $movement['unit_local'],
                    'unit_cost_foreign' => $movement['unit_foreign'],
                    'description' => $movement['line']->description,
                ]);

                // Dos filas de kardex por línea: el artículo sale de un lado y
                // entra al otro, y cada almacén tiene que poder leer su propio
                // historial completo.
                $this->writeKardex($company, $movement, $documentLine->id, $entry?->id, $postingDate, $createdBy, 'out');
                $this->writeKardex($company, $movement, $documentLine->id, $entry?->id, $postingDate, $createdBy, 'in');
            }

            foreach ($onHand as $itemId => $byWarehouse) {
                foreach ($byWarehouse as $warehouseId => $quantity) {
                    ItemWarehouse::updateOrCreate(
                        ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
                        ['on_hand' => $quantity],
                    );
                }
            }

            foreach ($binOnHand as $itemId => $byBin) {
                foreach ($byBin as $binId => $quantity) {
                    ItemBin::updateOrCreate(
                        ['item_id' => $itemId, 'warehouse_bin_id' => $binId],
                        ['on_hand' => $quantity],
                    );
                }
            }

            return $document->load('lines');
        });
    }

    private function writeKardex(
        Company $company,
        array $movement,
        int $documentLineId,
        ?int $journalEntryId,
        \DateTimeInterface $postingDate,
        ?int $createdBy,
        string $direction,
    ): void {
        $isOut = $direction === 'out';

        StockJournal::create([
            'company_id' => $company->id,
            'item_id' => $movement['item']->id,
            'warehouse_id' => $isOut ? $movement['from']->id : $movement['to']->id,
            'warehouse_bin_id' => $isOut ? $movement['fromBin']?->id : $movement['toBin']?->id,
            'inventory_document_line_id' => $documentLineId,
            'journal_entry_id' => $journalEntryId,
            'posting_date' => $postingDate->format('Y-m-d'),
            'direction' => $direction,
            'quantity' => $movement['line']->quantity,
            'unit_cost_local' => $movement['unit_local'],
            'unit_cost_foreign' => $movement['unit_foreign'],
            'total_cost_local' => $movement['total_local'],
            'total_cost_foreign' => bccomp($movement['unit_foreign'], '0.000000', 6) > 0
                ? $this->money(bcdiv($movement['total_local'], bcdiv($movement['unit_local'], $movement['unit_foreign'], 6), 10))
                : '0.00',
            'balance_quantity' => $isOut ? $movement['from_balance'] : $movement['to_balance'],
            // El promedio no cambia en un traslado; se repite el vigente para
            // que la columna siga contando la historia completa del artículo.
            'avg_cost_local_after' => $movement['unit_local'],
            'avg_cost_foreign_after' => $movement['unit_foreign'],
            'created_by' => $createdBy,
        ]);
    }

    private function activeWarehouse($warehouses, int $warehouseId): Warehouse
    {
        $warehouse = $warehouses->get($warehouseId)
            ?? throw new InvalidStockMovementException("El almacén id {$warehouseId} no existe en la compañía.");

        if ($warehouse->status !== 'active') {
            throw new InvalidStockMovementException("El almacén {$warehouse->code} está inactivo; no admite movimientos.");
        }

        return $warehouse;
    }

    private function sameAccounting(array $source, array $destination): bool
    {
        return $source['account_id'] === $destination['account_id']
            && $source['cost_allocation_rule_id'] === $destination['cost_allocation_rule_id'];
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
