<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\MissingExchangeRateException;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
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
 * Único punto de escritura del kardex. Llama a PostJournalService::post()
 * INLINE dentro de su propia transacción (Laravel anida vía SAVEPOINT), no
 * por eventos: si el asiento falla —cuenta sin configurar, período cerrado,
 * cuenta que no acepta movimientos— el movimiento de stock tampoco existe.
 * Mismo precedente que ApplyPaymentService dentro de post()
 * (docs/decisiones.md 2026-08-24 y 2026-09-13).
 *
 * El costo promedio ponderado es GLOBAL por artículo y se mantiene en moneda
 * local Y extranjera a la vez; cada salida se contabiliza con el tipo de
 * cambio implícito de ese promedio (frozenExchangeRate), nunca con el del
 * día: el inventario es una partida no monetaria y su costo no se revalúa.
 */
class PostStockMovementService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
        private readonly WarehouseBinResolver $binResolver,
    ) {}

    /**
     * @param  StockLineInput[]  $lines
     */
    public function post(
        Company $company,
        DocumentType $documentType,
        string $operation,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?string $description = null,
        ?int $createdBy = null,
        ?int $businessPartnerId = null,
        ?int $productionOrderId = null,
    ): InventoryDocument {
        if (! array_key_exists($operation, InventoryDocument::OPERATIONS)) {
            throw new InvalidStockMovementException("Operación de inventario desconocida: {$operation}.");
        }

        if (in_array($operation, InventoryDocument::PRODUCTION_OPERATIONS, true) && $productionOrderId === null) {
            throw new InvalidStockMovementException('Una emisión o recibo de producción requiere indicar la orden de fabricación.');
        }

        // Una entrada por compra genera un pasivo provisional contra el
        // proveedor (cuenta puente GR/IR) hasta que llegue su factura: sin
        // saber a quién se le debe, esa provisión no se puede liquidar.
        if ($operation === 'purchase_receipt' && $businessPartnerId === null) {
            throw new InvalidStockMovementException('Una entrada por compra requiere indicar el proveedor.');
        }

        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Un movimiento de inventario requiere al menos una línea.');
        }

        return DB::transaction(function () use ($company, $documentType, $operation, $documentDate, $postingDate, $lines, $description, $createdBy, $businessPartnerId, $productionOrderId) {
            $itemIds = array_values(array_unique(array_map(fn (StockLineInput $l) => $l->itemId, $lines)));
            $warehouseIds = array_values(array_unique(array_map(fn (StockLineInput $l) => $l->warehouseId, $lines)));

            // Lock pesimista ordenado por id: serializa los movimientos del
            // mismo artículo (sin esto dos salidas concurrentes pasan ambas
            // la validación de existencia y dejan el saldo negativo igual) y
            // el orden fijo evita deadlocks entre documentos cruzados.
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

            // Todas las existencias de los artículos involucrados, no solo las
            // de los almacenes del documento: el promedio es global, así que
            // necesita la cantidad total del artículo para recalcularse.
            $stockRows = ItemWarehouse::whereIn('item_id', $itemIds)->lockForUpdate()->get();

            $onHand = [];
            foreach ($stockRows as $row) {
                $onHand[$row->item_id][$row->warehouse_id] = (string) $row->on_hand;
            }

            // Ubicaciones (Fase 7): solo para los almacenes que las usan. En
            // los demás este arreglo queda vacío y nada cambia respecto de
            // antes — el costeo nunca las mira, son puramente logísticas.
            $bins = WarehouseBin::whereIn('warehouse_id', $warehouseIds)->get()->keyBy('id');

            $binOnHand = [];
            foreach (ItemBin::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $binOnHand[$row->item_id][$row->warehouse_bin_id] = (string) $row->on_hand;
            }

            $avgLocal = [];
            $avgForeign = [];
            foreach ($items as $item) {
                $avgLocal[$item->id] = (string) $item->avg_cost_local;
                $avgForeign[$item->id] = (string) $item->avg_cost_foreign;
            }

            if ($businessPartnerId !== null) {
                $supplier = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->id)
                    ->find($businessPartnerId);

                if (! $supplier) {
                    throw new InvalidStockMovementException("El socio de negocio id {$businessPartnerId} no existe en la compañía.");
                }

                if (! in_array($supplier->type, ['supplier', 'both'], true)) {
                    throw new InvalidStockMovementException(
                        "El socio de negocio {$supplier->code} ({$supplier->name}) no está registrado como proveedor."
                    );
                }
            }

            $rules = $this->glResolver->load($company);
            $rateToday = $this->rateOnOrBefore($company, $postingDate);

            $movements = [];
            $journalLines = [];

            foreach (array_values($lines) as $index => $line) {
                $item = $items->get($line->itemId)
                    ?? throw new InvalidStockMovementException("El artículo id {$line->itemId} no existe en la compañía.");

                $warehouse = $warehouses->get($line->warehouseId)
                    ?? throw new InvalidStockMovementException("El almacén id {$line->warehouseId} no existe en la compañía.");

                if (! $item->is_inventory_item) {
                    throw new InvalidStockMovementException(
                        "El artículo {$item->code} está marcado como servicio; no lleva kardex y no puede moverse en inventario."
                    );
                }

                if ($warehouse->status !== 'active') {
                    throw new InvalidStockMovementException("El almacén {$warehouse->code} está inactivo; no admite movimientos.");
                }

                $bin = $this->binResolver->resolve($warehouse, $line->warehouseBinId, $bins);

                $warehouseQty = $onHand[$item->id][$warehouse->id] ?? '0.000000';
                $globalQty = $this->globalQuantity($onHand, $item->id);

                // Con ubicaciones, la existencia disponible es la de la
                // ubicación indicada, no la del almacén entero: sacar de un
                // estante vacío no es válido aunque el almacén tenga stock.
                $availableQty = $bin
                    ? ($binOnHand[$item->id][$bin->id] ?? '0.000000')
                    : $warehouseQty;

                $movement = $this->resolveMovement(
                    $operation, $line, $item, $warehouse,
                    $warehouseQty, $availableQty, $globalQty,
                    $avgLocal[$item->id], $avgForeign[$item->id],
                    $rateToday,
                );

                // Un conteo que coincide con la existencia no mueve nada:
                // no genera fila de kardex ni línea de asiento.
                if ($movement === null) {
                    continue;
                }

                $avgLocal[$item->id] = $movement['avg_local_after'];
                $avgForeign[$item->id] = $movement['avg_foreign_after'];

                $newWarehouseQty = $movement['direction'] === 'in'
                    ? bcadd($warehouseQty, $movement['quantity'], 6)
                    : bcsub($warehouseQty, $movement['quantity'], 6);

                $onHand[$item->id][$warehouse->id] = $newWarehouseQty;

                if ($bin) {
                    $current = $binOnHand[$item->id][$bin->id] ?? '0.000000';

                    $binOnHand[$item->id][$bin->id] = $movement['direction'] === 'in'
                        ? bcadd($current, $movement['quantity'], 6)
                        : bcsub($current, $movement['quantity'], 6);
                }

                $movement['line_index'] = $index;
                $movement['item'] = $item;
                $movement['warehouse'] = $warehouse;
                $movement['bin'] = $bin;
                $movement['balance_quantity'] = $newWarehouseQty;
                $movements[] = $movement;

                // Entrada:          Debe Inventario / Haber Ajuste-aumento.
                // Entrada x compra: Debe Inventario / Haber GR/IR (pasivo no
                //                   facturado, que la factura del proveedor
                //                   liquidará después).
                // Salida:           Debe Ajuste-disminución / Haber Inventario.
                // Producción: la materia prima que sale NO va a gasto, se
                // traslada a Producto en Proceso; y el producto terminado que
                // entra descarga ese mismo WIP. Por eso ambas operaciones
                // comparten la misma contracuenta y solo cambia el sentido.
                $isIn = $movement['direction'] === 'in';
                $counterpartCategory = match (true) {
                    $operation === 'purchase_receipt' => 'gr_ir_clearing',
                    in_array($operation, InventoryDocument::PRODUCTION_OPERATIONS, true) => 'wip',
                    // Una salida por venta no es una baja: es el costo de lo
                    // vendido, y va a resultados por su propia cuenta.
                    $operation === 'sales_issue' => 'cogs',
                    $isIn => 'stock_increase',
                    default => 'stock_decrease',
                };

                $inventoryAccount = $this->glResolver->resolve(
                    $rules, 'inventory', $item, $warehouse, $documentType, $isIn ? 'debit' : 'credit'
                );

                $counterpartAccount = $this->glResolver->resolve(
                    $rules, $counterpartCategory, $item, $warehouse, $documentType, $isIn ? 'credit' : 'debit'
                );

                $amount = $movement['total_cost_local'];
                $lineLabel = $line->description ?? "{$item->code} — {$item->name}";

                $journalLines[] = new JournalLineInput(
                    accountId: $inventoryAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isIn ? $amount : 0,
                    credit: $isIn ? 0 : $amount,
                    description: $lineLabel,
                    costAllocationRuleId: $inventoryAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $movement['exchange_rate'],
                );

                $journalLines[] = new JournalLineInput(
                    accountId: $counterpartAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isIn ? 0 : $amount,
                    credit: $isIn ? $amount : 0,
                    description: $lineLabel,
                    costAllocationRuleId: $counterpartAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $movement['exchange_rate'],
                );
            }

            if (empty($movements)) {
                throw new InvalidStockMovementException(
                    'Ninguna línea produce movimiento: el conteo coincide con la existencia registrada en todos los artículos.'
                );
            }

            $journalEntry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $journalLines,
                description: $description ?? InventoryDocument::OPERATIONS[$operation],
                createdBy: $createdBy,
            );

            $document = InventoryDocument::create([
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'journal_entry_id' => $journalEntry->id,
                'operation' => $operation,
                'business_partner_id' => $businessPartnerId,
                'production_order_id' => $productionOrderId,
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
                    'warehouse_id' => $movement['warehouse']->id,
                    'warehouse_bin_id' => $movement['bin']?->id,
                    'quantity' => $movement['quantity'],
                    'unit_cost_local' => $movement['unit_cost_local'],
                    'unit_cost_foreign' => $movement['unit_cost_foreign'],
                    'description' => $lines[$movement['line_index']]->description,
                ]);

                StockJournal::create([
                    'company_id' => $company->id,
                    'item_id' => $movement['item']->id,
                    'warehouse_id' => $movement['warehouse']->id,
                    'warehouse_bin_id' => $movement['bin']?->id,
                    'inventory_document_line_id' => $documentLine->id,
                    'journal_entry_id' => $journalEntry->id,
                    'posting_date' => $postingDate->format('Y-m-d'),
                    'direction' => $movement['direction'],
                    'quantity' => $movement['quantity'],
                    'unit_cost_local' => $movement['unit_cost_local'],
                    'unit_cost_foreign' => $movement['unit_cost_foreign'],
                    'total_cost_local' => $movement['total_cost_local'],
                    'total_cost_foreign' => $movement['total_cost_foreign'],
                    'balance_quantity' => $movement['balance_quantity'],
                    'avg_cost_local_after' => $movement['avg_local_after'],
                    'avg_cost_foreign_after' => $movement['avg_foreign_after'],
                    'created_by' => $createdBy,
                ]);
            }

            foreach ($items as $item) {
                $item->update([
                    'avg_cost_local' => $avgLocal[$item->id],
                    'avg_cost_foreign' => $avgForeign[$item->id],
                ]);
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

    /**
     * Traduce una línea digitada a un movimiento concreto, o null si no hay
     * nada que mover. Devuelve además el promedio resultante, que es lo único
     * que una entrada modifica: una salida y un conteo se valúan al promedio
     * vigente y no lo alteran (sacar unidades, o encontrarlas en otro lugar,
     * no cambia cuánto costaron).
     */
    private function resolveMovement(
        string $operation,
        StockLineInput $line,
        Item $item,
        Warehouse $warehouse,
        string $warehouseQty,
        string $availableQty,
        string $globalQty,
        string $avgLocal,
        string $avgForeign,
        string $rateToday,
    ): ?array {
        // Toda entrada se costea igual —promedio ponderado con el costo
        // digitado—; lo único que cambia entre ellas es contra qué cuenta se
        // acredita, y eso se resuelve fuera de este método. En un recibo de
        // producción ese costo no lo digita el usuario: se lo pasa
        // PostProductionService, que lo deriva del WIP acumulado.
        if (in_array($operation, ['goods_receipt', 'purchase_receipt', 'production_receipt'], true)) {
            if (bccomp($line->quantity, '0.000000', 6) <= 0) {
                throw new InvalidStockMovementException("La entrada del artículo {$item->code} requiere una cantidad mayor a cero.");
            }

            // Costo cero dejaría una línea de asiento en 0.00 (que
            // JournalLineInput rechaza) y un promedio sin sentido. Mercancía
            // recibida sin costo —muestras, bonificaciones— se resolverá
            // cuando exista el caso; hoy se rechaza explícitamente en vez de
            // contabilizar algo que no cuadra.
            if ($line->unitCostLocal === null || bccomp($line->unitCostLocal, '0.000000', 6) <= 0) {
                throw new InvalidStockMovementException("La entrada del artículo {$item->code} requiere un costo unitario mayor a cero.");
            }

            $unitLocal = $line->unitCostLocal;
            $unitForeign = bcdiv($unitLocal, $rateToday, 6);
            $quantity = $line->quantity;

            $newGlobal = bcadd($globalQty, $quantity, 6);

            $avgLocalAfter = bcdiv(
                bcadd(bcmul($globalQty, $avgLocal, 12), bcmul($quantity, $unitLocal, 12), 12),
                $newGlobal, 6
            );

            $avgForeignAfter = bcdiv(
                bcadd(bcmul($globalQty, $avgForeign, 12), bcmul($quantity, $unitForeign, 12), 12),
                $newGlobal, 6
            );

            return $this->movement('in', $quantity, $unitLocal, $unitForeign, $rateToday, $avgLocalAfter, $avgForeignAfter);
        }

        if (in_array($operation, ['goods_issue', 'production_issue', 'sales_issue'], true)) {
            if (bccomp($line->quantity, '0.000000', 6) <= 0) {
                throw new InvalidStockMovementException("La salida del artículo {$item->code} requiere una cantidad mayor a cero.");
            }

            $this->assertEnoughStock($item, $warehouse, $availableQty, $line->quantity);
            $this->assertHasCost($item, $avgLocal, $avgForeign);

            $rate = bcdiv($avgLocal, $avgForeign, 6);

            return $this->movement('out', $line->quantity, $avgLocal, $avgForeign, $rate, $avgLocal, $avgForeign);
        }

        // count_adjustment: $line->quantity es la cantidad CONTADA. Con
        // ubicaciones se cuenta la ubicación, no el almacén entero.
        $delta = bcsub($line->quantity, $availableQty, 6);

        if (bccomp($delta, '0.000000', 6) === 0) {
            return null;
        }

        $this->assertHasCost($item, $avgLocal, $avgForeign);

        $rate = bcdiv($avgLocal, $avgForeign, 6);
        $isIncrease = bccomp($delta, '0.000000', 6) > 0;
        $quantity = $isIncrease ? $delta : bcmul($delta, '-1', 6);

        return $this->movement(
            $isIncrease ? 'in' : 'out',
            $quantity, $avgLocal, $avgForeign, $rate, $avgLocal, $avgForeign
        );
    }

    /**
     * total_cost_foreign se deriva del total local con la MISMA fórmula que
     * usa PostJournalService (dividir y redondear a 2), no multiplicando la
     * cantidad por el costo unitario en FC: así el kardex guarda exactamente
     * el monto que quedó en el asiento y no difiere por un céntimo de
     * redondeo, que es lo que haría imposible cuadrar inventario contra mayor.
     */
    private function movement(
        string $direction,
        string $quantity,
        string $unitLocal,
        string $unitForeign,
        string $rate,
        string $avgLocalAfter,
        string $avgForeignAfter,
    ): array {
        $totalLocal = $this->money(bcmul($quantity, $unitLocal, 12));

        return [
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost_local' => $unitLocal,
            'unit_cost_foreign' => $unitForeign,
            'exchange_rate' => $rate,
            'total_cost_local' => $totalLocal,
            'total_cost_foreign' => $this->money(bcdiv($totalLocal, $rate, 10)),
            'avg_local_after' => $avgLocalAfter,
            'avg_foreign_after' => $avgForeignAfter,
        ];
    }

    private function assertEnoughStock(Item $item, Warehouse $warehouse, string $available, string $requested): void
    {
        if (bccomp($requested, $available, 6) > 0) {
            throw new InsufficientStockException(
                "No hay existencia suficiente de {$item->code} en el almacén {$warehouse->code}: ".
                'se piden '.rtrim(rtrim($requested, '0'), '.').' y hay '.rtrim(rtrim($available, '0'), '.').'.'
            );
        }
    }

    private function assertHasCost(Item $item, string $avgLocal, string $avgForeign): void
    {
        if (bccomp($avgLocal, '0.000000', 6) <= 0 || bccomp($avgForeign, '0.000000', 6) <= 0) {
            throw new InvalidStockMovementException(
                "El artículo {$item->code} no tiene costo promedio registrado; registrá primero una entrada con costo."
            );
        }
    }

    private function globalQuantity(array $onHand, int $itemId): string
    {
        $total = '0.000000';

        foreach ($onHand[$itemId] ?? [] as $quantity) {
            $total = bcadd($total, $quantity, 6);
        }

        return $total;
    }

    private function rateOnOrBefore(Company $company, \DateTimeInterface $date): string
    {
        $rate = ExchangeRate::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('currency_id', $company->foreign_currency_id)
            ->whereDate('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();

        if (! $rate) {
            throw new MissingExchangeRateException(
                "No hay tipo de cambio registrado para la moneda extranjera en o antes de {$date->format('Y-m-d')}."
            );
        }

        return (string) $rate->rate;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
