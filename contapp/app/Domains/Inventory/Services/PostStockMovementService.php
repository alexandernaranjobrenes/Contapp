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
use App\Domains\Inventory\DataTransferObjects\ImportDetailsInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Exceptions\UnvoidableInventoryDocumentException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\ItemLotStock;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use Illuminate\Support\Collection;
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
        private readonly ItemLotResolver $lotResolver,
        private readonly ItemSerialResolver $serialResolver,
        private readonly PurchaseOrderService $purchaseOrders,
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
        ?int $sourceDocumentId = null,
        ?ImportDetailsInput $import = null,
        // Orden de compra que origina esta recepción. Nullable a propósito:
        // no toda compra pasa por una orden formal, y exigirla rompería el
        // flujo que ya funciona.
        ?int $purchaseOrderId = null,
    ): InventoryDocument {
        if (! array_key_exists($operation, InventoryDocument::OPERATIONS)) {
            throw new InvalidStockMovementException("Operación de inventario desconocida: {$operation}.");
        }

        // Solo una compra puede ser importación: un ajuste o una emisión a
        // producción no pasan por aduana, y marcarlos habilitaría cargarles
        // costos de nacionalización que no les corresponden.
        if ($import !== null && $operation !== 'purchase_receipt') {
            throw new InvalidStockMovementException(
                'Solo una entrada por compra puede marcarse como importación.'
            );
        }

        if (in_array($operation, InventoryDocument::PRODUCTION_OPERATIONS, true) && $productionOrderId === null) {
            throw new InvalidStockMovementException('Una emisión o recibo de producción requiere indicar la orden de fabricación.');
        }

        // Una entrada por compra genera un pasivo provisional contra el
        // proveedor (cuenta puente GR/IR) hasta que llegue su factura: sin
        // saber a quién se le debe, esa provisión no se puede liquidar.
        if (in_array($operation, InventoryDocument::PURCHASE_OPERATIONS, true) && $businessPartnerId === null) {
            throw new InvalidStockMovementException('Una entrada por compra requiere indicar el proveedor.');
        }

        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Un movimiento de inventario requiere al menos una línea.');
        }

        return DB::transaction(function () use ($company, $documentType, $operation, $documentDate, $postingDate, $lines, $description, $createdBy, $businessPartnerId, $productionOrderId, $sourceDocumentId, $import, $purchaseOrderId) {
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
            $reserved = [];
            foreach ($stockRows as $row) {
                $onHand[$row->item_id][$row->warehouse_id] = (string) $row->on_hand;
                $reserved[$row->item_id][$row->warehouse_id] = (string) $row->reserved;
            }

            // Ubicaciones (Fase 7): solo para los almacenes que las usan. En
            // los demás este arreglo queda vacío y nada cambia respecto de
            // antes — el costeo nunca las mira, son puramente logísticas.
            $bins = WarehouseBin::whereIn('warehouse_id', $warehouseIds)->get()->keyBy('id');

            $binOnHand = [];
            foreach (ItemBin::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $binOnHand[$row->item_id][$row->warehouse_bin_id] = (string) $row->on_hand;
            }

            // Lotes (Fase 8): solo para los artículos que los manejan. En los
            // demás estos arreglos quedan vacíos y nada cambia respecto de
            // antes — igual que las ubicaciones, el costeo no los mira nunca.
            $lots = ItemLot::whereIn('item_id', $itemIds)->get()->keyBy('id');

            $lotOnHand = [];
            foreach (ItemLotStock::whereIn('item_lot_id', $lots->keys())->lockForUpdate()->get() as $row) {
                $lotOnHand[$row->item_lot_id][$this->lotSlot($row->warehouse_id, $row->warehouse_bin_id)] = (string) $row->on_hand;
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
                $lot = $this->lotResolver->resolve($item, $line->itemLotId, $lots, $operation, $postingDate);

                $warehouseQty = $onHand[$item->id][$warehouse->id] ?? '0.000000';
                $globalQty = $this->globalQuantity($onHand, $item->id);

                // Con ubicaciones, la existencia disponible es la de la
                // ubicación indicada, no la del almacén entero: sacar de un
                // estante vacío no es válido aunque el almacén tenga stock.
                //
                // Con lotes manda el lote, que es el nivel más fino: sacar 10
                // de un lote que solo tiene 3 no es válido aunque la
                // ubicación tenga 50 repartidas en otros lotes. La precedencia
                // es lote > ubicación > almacén.
                $lotSlot = $this->lotSlot($warehouse->id, $bin?->id);

                $availableQty = match (true) {
                    $lot !== null => $lotOnHand[$lot->id][$lotSlot] ?? '0.000000',
                    $bin !== null => $binOnHand[$item->id][$bin->id] ?? '0.000000',
                    default => $warehouseQty,
                };

                // Lo apartado por órdenes de pedido no está libre: una venta
                // a otro cliente no puede llevárselo. La reserva es por
                // almacén, así que acota la existencia del almacén aunque el
                // conteo disponible venga de una ubicación.
                $freeQty = bcsub($warehouseQty, $reserved[$item->id][$warehouse->id] ?? '0.000000', 6);

                $movement = $this->resolveMovement(
                    $operation, $line, $item, $warehouse,
                    $warehouseQty, $availableQty, $globalQty,
                    $avgLocal[$item->id], $avgForeign[$item->id],
                    $rateToday, $freeQty,
                );

                // Un conteo que coincide con la existencia no mueve nada:
                // no genera fila de kardex ni línea de asiento.
                if ($movement === null) {
                    continue;
                }

                // Series (Fase 9). Se resuelven DESPUÉS del movimiento porque
                // la validación depende de la dirección, que un conteo solo
                // conoce una vez calculado el delta: contar de más es una
                // entrada y contar de menos una salida, y cada una valida
                // distinto.
                $serials = $movement['direction'] === 'in'
                    ? $this->serialResolver->resolveForReceipt($item, $line->serialNumbers, $movement['quantity'])
                    : $this->serialResolver->resolveForIssue(
                        $item, $line->serialNumbers, $movement['quantity'], $warehouse->id, $bin?->id
                    );

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

                if ($lot) {
                    $current = $lotOnHand[$lot->id][$lotSlot] ?? '0.000000';

                    $lotOnHand[$lot->id][$lotSlot] = $movement['direction'] === 'in'
                        ? bcadd($current, $movement['quantity'], 6)
                        : bcsub($current, $movement['quantity'], 6);
                }

                $movement['line_index'] = $index;
                $movement['item'] = $item;
                $movement['warehouse'] = $warehouse;
                $movement['bin'] = $bin;
                $movement['lot'] = $lot;
                $movement['serials'] = $serials;
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
                    in_array($operation, InventoryDocument::PURCHASE_OPERATIONS, true) => 'gr_ir_clearing',
                    in_array($operation, InventoryDocument::PRODUCTION_OPERATIONS, true) => 'wip',
                    // Una salida por venta no es una baja: es el costo de lo
                    // vendido, y va a resultados por su propia cuenta. La
                    // devolución del cliente usa la misma cuenta al revés:
                    // reingresa la mercancía descargando ese costo.
                    in_array($operation, ['sales_issue', 'sales_return'], true) => 'cogs',
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
                'source_document_id' => $sourceDocumentId,
                'purchase_order_id' => $purchaseOrderId,
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'description' => $description,
                'status' => 'posted',
                'created_by' => $createdBy,
            ] + ($import?->toAttributes() ?? []));

            foreach ($movements as $position => $movement) {
                $documentLine = $document->lines()->create([
                    'line_number' => $position + 1,
                    'item_id' => $movement['item']->id,
                    'warehouse_id' => $movement['warehouse']->id,
                    'warehouse_bin_id' => $movement['bin']?->id,
                    'item_lot_id' => $movement['lot']?->id,
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
                    'item_lot_id' => $movement['lot']?->id,
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

                $this->persistSerials($movement, $document, $postingDate);
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

            $this->persistLotStock($lotOnHand);

            // Descarga el pendiente de la orden de compra DENTRO de la misma
            // transacción, igual que la factura consume el pedido de venta:
            // si el asiento o el kardex fallan, la orden tampoco queda
            // marcada como recibida.
            if ($purchaseOrderId !== null) {
                $order = PurchaseOrder::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->id)
                    ->find($purchaseOrderId);

                if (! $order) {
                    throw new InvalidStockMovementException("La orden de compra id {$purchaseOrderId} no existe en la compañía.");
                }

                if ($order->business_partner_id !== $businessPartnerId) {
                    throw new InvalidStockMovementException(
                        "La orden de compra {$order->number} es de otro proveedor."
                    );
                }

                $this->purchaseOrders->receive($order, array_map(fn (array $m) => [
                    'item_id' => $m['item']->id,
                    'warehouse_id' => $m['warehouse']->id,
                    'quantity' => $m['quantity'],
                ], $movements));
            }

            return $document->load('lines');
        });
    }

    /**
     * Mueve las series del movimiento. A diferencia de lotes y ubicaciones,
     * que suman y restan cantidades, acá se cambia el ESTADO y la UBICACIÓN
     * de filas concretas: una serie no tiene cantidad, tiene lugar.
     *
     * Una entrada crea la serie si es nueva y la reactiva si es una que
     * había salido y volvió (una devolución de cliente, típicamente). Por eso
     * es updateOrCreate y no create: el maestro conserva la unidad con su
     * historial en vez de duplicarla.
     */
    private function persistSerials(array $movement, InventoryDocument $document, \DateTimeInterface $postingDate): void
    {
        if ($movement['serials'] === [] || $movement['serials'] instanceof Collection && $movement['serials']->isEmpty()) {
            return;
        }

        if ($movement['direction'] === 'in') {
            foreach ($movement['serials'] as $serialNumber) {
                ItemSerial::updateOrCreate(
                    ['item_id' => $movement['item']->id, 'serial_number' => $serialNumber],
                    [
                        'status' => 'in_stock',
                        'warehouse_id' => $movement['warehouse']->id,
                        'warehouse_bin_id' => $movement['bin']?->id,
                        'item_lot_id' => $movement['lot']?->id,
                        'received_document_id' => $document->id,
                        'received_at' => $postingDate->format('Y-m-d'),
                        // Al volver a entrar deja de estar entregada: los
                        // datos de salida anteriores dejarían creyendo que
                        // sigue en poder del cliente.
                        'issued_document_id' => null,
                        'issued_at' => null,
                    ],
                );
            }

            return;
        }

        foreach ($movement['serials'] as $serial) {
            $serial->update([
                'status' => 'issued',
                // Se conserva el último almacén donde estuvo: saber de dónde
                // salió es parte de la trazabilidad, y ponerlo en null
                // perdería ese dato sin ganar nada.
                'issued_document_id' => $document->id,
                'issued_at' => $postingDate->format('Y-m-d'),
            ]);
        }
    }

    /**
     * Clave de la existencia de un lote: un lote puede estar repartido entre
     * almacenes y, dentro de uno, entre ubicaciones. El 0 representa "sin
     * ubicación" para que un almacén que no las usa tenga una clave estable
     * (null no sirve como índice de arreglo en PHP).
     */
    private function lotSlot(int $warehouseId, ?int $binId): string
    {
        return $warehouseId.':'.($binId ?? 0);
    }

    /**
     * @param  array<int, array<string, string>>  $lotOnHand
     */
    private function persistLotStock(array $lotOnHand): void
    {
        foreach ($lotOnHand as $lotId => $bySlot) {
            foreach ($bySlot as $slot => $quantity) {
                [$warehouseId, $binId] = explode(':', $slot);

                ItemLotStock::updateOrCreate(
                    [
                        'item_lot_id' => $lotId,
                        'warehouse_id' => (int) $warehouseId,
                        'warehouse_bin_id' => ((int) $binId) ?: null,
                    ],
                    ['on_hand' => $quantity],
                );
            }
        }
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
        string $freeQty = '0.000000',
    ): ?array {
        // Toda entrada se costea igual —promedio ponderado con el costo
        // digitado—; lo único que cambia entre ellas es contra qué cuenta se
        // acredita, y eso se resuelve fuera de este método. En un recibo de
        // producción ese costo no lo digita el usuario: se lo pasa
        // PostProductionService, que lo deriva del WIP acumulado.
        // La devolución de un cliente entra como cualquier otra entrada, pero
        // su costo no lo digita nadie: se lo pasa PostSalesDocumentService,
        // que lo toma de la salida original. Reingresar al promedio de hoy
        // dejaría el costo de ventas sin cerrar.
        if (in_array($operation, ['goods_receipt', 'purchase_receipt', 'production_receipt', 'sales_return'], true)) {
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

        if (in_array($operation, ['goods_issue', 'production_issue', 'sales_issue', 'purchase_return'], true)) {
            if (bccomp($line->quantity, '0.000000', 6) <= 0) {
                throw new InvalidStockMovementException("La salida del artículo {$item->code} requiere una cantidad mayor a cero.");
            }

            $this->assertEnoughStock($item, $warehouse, $availableQty, $line->quantity);
            $this->assertNotReserved($item, $warehouse, $freeQty, $line->quantity);
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

    /**
     * Lo apartado por una orden de pedido no está disponible para otra salida.
     * Se valida aparte de la existencia para poder decir POR QUÉ no alcanza:
     * "hay 100 pero 80 están apartadas" es un problema distinto de "hay 20".
     */
    private function assertNotReserved(Item $item, Warehouse $warehouse, string $free, string $requested): void
    {
        if (bccomp($requested, $free, 6) > 0) {
            throw new InsufficientStockException(
                "No hay existencia libre de {$item->code} en el almacén {$warehouse->code}: ".
                'se piden '.rtrim(rtrim($requested, '0'), '.').' y solo '.rtrim(rtrim($free, '0'), '.').
                ' están sin apartar por órdenes de pedido.'
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

    /**
     * Anula una entrada por compra que todavía nadie facturó. No borra nada:
     * el original queda en status='voided' y nace un documento espejo que
     * saca del kardex la MISMA cantidad al MISMO costo con que entró —no al
     * promedio de hoy—, apuntado con reversal_of_id, y cuyo asiento es la
     * reversión exacta del asiento original (PostJournalService::reverse(),
     * mismos tipos de cambio), de modo que la cuenta puente GR/IR vuelve a
     * cerrar en cero y el inventario queda como si la compra nunca hubiera
     * ocurrido.
     *
     * El reverso al costo original —y no al promedio— es lo que hace la
     * anulación exacta: si entre medio entró más mercancía del mismo artículo
     * a otro precio, sacar al promedio dejaría un residuo en la cuenta de
     * inventario que nadie podría explicar.
     */
    public function void(
        Company $company,
        InventoryDocument $document,
        \DateTimeInterface $postingDate,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryDocument {
        if ($document->company_id !== $company->id) {
            throw new \InvalidArgumentException('El documento a anular no pertenece a la compañía indicada.');
        }

        return DB::transaction(function () use ($company, $document, $postingDate, $description, $createdBy) {
            // Relectura bajo lock: entre que la pantalla mostró el botón y
            // llegó este POST, otra sesión pudo facturar o anular el mismo
            // documento. El estado que vale es el que se lee acá adentro.
            $receipt = InventoryDocument::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($document->id);

            $this->assertVoidable($receipt);

            $receipt->load('lines');

            $original = StockJournal::withoutGlobalScope(CompanyScope::class)
                ->whereIn('inventory_document_line_id', $receipt->lines->pluck('id'))
                ->get()
                ->keyBy('inventory_document_line_id');

            $itemIds = $receipt->lines->pluck('item_id')->unique()->values()->all();
            $warehouseIds = $receipt->lines->pluck('warehouse_id')->unique()->values()->all();

            // Mismo lock ordenado por id que post(): sin él, una salida
            // concurrente del mismo artículo podría colarse entre la
            // validación de existencia y la escritura del kardex.
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

            $binOnHand = [];
            foreach (ItemBin::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $binOnHand[$row->item_id][$row->warehouse_bin_id] = (string) $row->on_hand;
            }

            // La existencia por lote se revierte igual que la del almacén y
            // la de la ubicación: si la entrada trajo lote, la anulación lo
            // devuelve. Un documento anterior a la Fase 8 no tiene lote y
            // este arreglo queda vacío.
            $lotIds = $receipt->lines->pluck('item_lot_id')->filter()->unique()->values();

            $lotOnHand = [];
            foreach (ItemLotStock::whereIn('item_lot_id', $lotIds)->lockForUpdate()->get() as $row) {
                $lotOnHand[$row->item_lot_id][$this->lotSlot($row->warehouse_id, $row->warehouse_bin_id)] = (string) $row->on_hand;
            }

            $avgLocal = [];
            $avgForeign = [];
            foreach ($items as $item) {
                $avgLocal[$item->id] = (string) $item->avg_cost_local;
                $avgForeign[$item->id] = (string) $item->avg_cost_foreign;
            }

            $movements = [];

            foreach ($receipt->lines as $line) {
                $item = $items->get($line->item_id);
                $warehouse = $warehouses->get($line->warehouse_id);
                $kardex = $original->get($line->id);

                if (! $kardex) {
                    throw new UnvoidableInventoryDocumentException(
                        "La línea {$line->line_number} no tiene movimiento de kardex; el documento está incompleto y no se puede anular."
                    );
                }

                $quantity = (string) $kardex->quantity;

                // La mercancía tiene que seguir donde se recibió: si ya salió
                // —vendida, trasladada, emitida a producción— la anulación
                // dejaría la existencia negativa. La vía en ese caso es
                // facturar y emitir la nota de crédito.
                // Misma precedencia que en post(): lote > ubicación > almacén.
                // Anular una entrada cuyo lote ya salió tiene que fallar
                // aunque el almacén siga teniendo unidades de otros lotes.
                $lotSlot = $this->lotSlot($warehouse->id, $line->warehouse_bin_id);

                $available = match (true) {
                    $line->item_lot_id !== null => $lotOnHand[$line->item_lot_id][$lotSlot] ?? '0.000000',
                    $line->warehouse_bin_id !== null => $binOnHand[$item->id][$line->warehouse_bin_id] ?? '0.000000',
                    default => $onHand[$item->id][$warehouse->id] ?? '0.000000',
                };

                $this->assertEnoughStock($item, $warehouse, $available, $quantity);

                $globalQty = $this->globalQuantity($onHand, $item->id);
                $newGlobal = bcsub($globalQty, $quantity, 6);

                // Se retira del acumulado exactamente el valor que la entrada
                // había aportado. Si eso dejara el inventario valiendo menos
                // que cero, es que esa mercancía ya se consumió mezclada en el
                // promedio y la anulación ya no puede ser exacta.
                $valueLocal = bcsub(
                    bcmul($globalQty, $avgLocal[$item->id], 12),
                    bcmul($quantity, (string) $kardex->unit_cost_local, 12), 12
                );

                $valueForeign = bcsub(
                    bcmul($globalQty, $avgForeign[$item->id], 12),
                    bcmul($quantity, (string) $kardex->unit_cost_foreign, 12), 12
                );

                if (bccomp($valueLocal, '0', 6) < 0 || bccomp($valueForeign, '0', 6) < 0) {
                    throw new UnvoidableInventoryDocumentException(
                        "La mercancía de {$item->code} ya se consumió mezclada en el costo promedio; anular dejaría el inventario ".
                        'valiendo menos que cero. Facturá la entrada y emití una nota de crédito en su lugar.'
                    );
                }

                // Con el artículo agotado no queda promedio que recalcular: el
                // valor es cero por definición y el último costo se conserva,
                // igual que hace una salida común.
                if (bccomp($newGlobal, '0.000000', 6) > 0) {
                    $avgLocal[$item->id] = bcdiv($valueLocal, $newGlobal, 6);
                    $avgForeign[$item->id] = bcdiv($valueForeign, $newGlobal, 6);
                }

                $onHand[$item->id][$warehouse->id] = bcsub(
                    $onHand[$item->id][$warehouse->id] ?? '0.000000', $quantity, 6
                );

                if ($line->warehouse_bin_id) {
                    $binOnHand[$item->id][$line->warehouse_bin_id] = bcsub(
                        $binOnHand[$item->id][$line->warehouse_bin_id] ?? '0.000000', $quantity, 6
                    );
                }

                if ($line->item_lot_id) {
                    $lotOnHand[$line->item_lot_id][$lotSlot] = bcsub(
                        $lotOnHand[$line->item_lot_id][$lotSlot] ?? '0.000000', $quantity, 6
                    );
                }

                $movements[] = [
                    'line' => $line,
                    'kardex' => $kardex,
                    'item' => $item,
                    'warehouse' => $warehouse,
                    'quantity' => $quantity,
                    'balance_quantity' => $onHand[$item->id][$warehouse->id],
                    'avg_local_after' => $avgLocal[$item->id],
                    'avg_foreign_after' => $avgForeign[$item->id],
                ];
            }

            // El asiento espejo lo arma PostJournalService: invierte línea por
            // línea en las tres monedas con los tipos de cambio originales, y
            // deja el asiento de la entrada en 'voided'. Acá no se recalcula
            // nada de contabilidad.
            $reversalEntry = $this->postJournalService->reverse(
                company: $company,
                original: $receipt->journalEntry()->withoutGlobalScope(CompanyScope::class)->firstOrFail(),
                postingDate: $postingDate,
                description: $description ?? "Anulación de entrada por compra #{$receipt->id}",
                createdBy: $createdBy,
            );

            $reversal = InventoryDocument::create([
                'company_id' => $company->id,
                'document_type_id' => $receipt->document_type_id,
                'journal_entry_id' => $reversalEntry->id,
                'operation' => 'purchase_receipt_void',
                'business_partner_id' => $receipt->business_partner_id,
                'source_document_id' => $receipt->source_document_id,
                'document_date' => $postingDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'description' => $description ?? "Anulación de entrada por compra #{$receipt->id}",
                'status' => 'posted',
                'reversal_of_id' => $receipt->id,
                'created_by' => $createdBy,
            ]);

            foreach ($movements as $position => $movement) {
                $kardex = $movement['kardex'];

                $reversalLine = $reversal->lines()->create([
                    'line_number' => $position + 1,
                    'item_id' => $movement['item']->id,
                    'warehouse_id' => $movement['warehouse']->id,
                    'warehouse_bin_id' => $movement['line']->warehouse_bin_id,
                    'item_lot_id' => $movement['line']->item_lot_id,
                    'quantity' => $movement['quantity'],
                    'unit_cost_local' => $kardex->unit_cost_local,
                    'unit_cost_foreign' => $kardex->unit_cost_foreign,
                    'description' => $movement['line']->description,
                ]);

                StockJournal::create([
                    'company_id' => $company->id,
                    'item_id' => $movement['item']->id,
                    'warehouse_id' => $movement['warehouse']->id,
                    'warehouse_bin_id' => $movement['line']->warehouse_bin_id,
                    'item_lot_id' => $movement['line']->item_lot_id,
                    'inventory_document_line_id' => $reversalLine->id,
                    'journal_entry_id' => $reversalEntry->id,
                    'posting_date' => $postingDate->format('Y-m-d'),
                    'direction' => 'out',
                    'quantity' => $movement['quantity'],
                    // Los mismos importes de la fila original, con el signo
                    // contrario: el kardex y el mayor se mueven por el mismo
                    // monto, que es la única forma de que sigan cuadrando.
                    'unit_cost_local' => $kardex->unit_cost_local,
                    'unit_cost_foreign' => $kardex->unit_cost_foreign,
                    'total_cost_local' => $kardex->total_cost_local,
                    'total_cost_foreign' => $kardex->total_cost_foreign,
                    'balance_quantity' => $movement['balance_quantity'],
                    'avg_cost_local_after' => $movement['avg_local_after'],
                    'avg_cost_foreign_after' => $movement['avg_foreign_after'],
                    'reversal_of_id' => $kardex->id,
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
                foreach ($byWarehouse as $warehouseId => $stock) {
                    ItemWarehouse::updateOrCreate(
                        ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
                        ['on_hand' => $stock],
                    );
                }
            }

            foreach ($binOnHand as $itemId => $byBin) {
                foreach ($byBin as $binId => $stock) {
                    ItemBin::updateOrCreate(
                        ['item_id' => $itemId, 'warehouse_bin_id' => $binId],
                        ['on_hand' => $stock],
                    );
                }
            }

            $this->persistLotStock($lotOnHand);

            // Las series que entraron con esta recepción salen del maestro:
            // la unidad nunca ingresó de verdad. Se BORRAN y no se marcan
            // como entregadas, porque "entregada" significa que alguien la
            // tiene, y acá el documento se anuló. Solo las que siguen en
            // existencia: si una ya se vendió, la anulación habría fallado
            // antes por existencia insuficiente.
            ItemSerial::where('received_document_id', $receipt->id)
                ->inStock()
                ->delete();

            $receipt->update(['status' => 'voided']);

            return $reversal->load('lines');
        });
    }

    private function assertVoidable(InventoryDocument $receipt): void
    {
        if (! in_array($receipt->operation, InventoryDocument::VOIDABLE_OPERATIONS, true)) {
            throw new UnvoidableInventoryDocumentException(
                'Solo una entrada por compra se anula; el resto del ciclo se corrige con su documento espejo.'
            );
        }

        if ($receipt->status !== 'posted') {
            throw new UnvoidableInventoryDocumentException('El documento ya fue anulado.');
        }

        if ($receipt->invoice_journal_entry_id !== null) {
            throw new UnvoidableInventoryDocumentException(
                'La entrada ya tiene factura del proveedor: la deuda es real y se deshace con una nota de crédito, no anulando.'
            );
        }

        // Un costo de importación ya capitalizado movió el promedio de los
        // artículos por su cuenta; deshacerlo no es parte de esta anulación.
        if ($receipt->landedCostDocuments()->where('status', 'posted')->exists()) {
            throw new UnvoidableInventoryDocumentException(
                'La entrada tiene costos de importación aplicados; anulá primero esos documentos.'
            );
        }
    }
}
