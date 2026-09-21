<?php

namespace App\Domains\Inventory\Services;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseOrderException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Models\PurchaseOrderLine;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Dueño único de lo pendiente por recibir (item_warehouses.ordered), igual
 * que SalesOrderService lo es de la reserva y PostStockMovementService de la
 * existencia. Cuatro momentos lo mueven:
 *
 *   place()    ordena                    ordered += lo pedido
 *   receive()  descarga al recibir       ordered -= lo recibido
 *   cancel()   suelta sin recibir        ordered -= lo que faltaba
 *   close()    da por cerrado el saldo   ordered -= lo que faltaba
 *
 * Una orden de compra NO genera asiento ni consecutivo fiscal: es un
 * compromiso, no un hecho económico. No hay mercancía ni pasivo todavía —
 * eso llega con la entrada por compra (contra GR/IR) y su factura.
 *
 * ── La asimetría con la reserva, que es deliberada ───────────────────────
 *
 * `reserved` RESTRINGE y `ordered` INFORMA. Lo apartado por un pedido no se
 * le puede vender a otro, así que toda salida valida contra lo libre; que
 * vengan 100 unidades en camino, en cambio, no cambia nada de lo que se
 * puede hacer hoy con las que hay. Por eso este service no tocó ninguna
 * validación existente, a diferencia de la reserva, que obligó a actualizar
 * hasta los traslados.
 *
 * Consecuencia práctica: **no hay tope para ordenar**. Un pedido de venta no
 * puede apartar más de lo libre porque prometer mercancía que no existe no
 * es apartar; una orden de compra puede pedir la cantidad que sea, que es
 * justamente para lo que sirve.
 *
 * ── cancel() y close() hacen lo mismo y significan cosas distintas ───────
 *
 * Cancelar es "esta orden no debió existir / el proveedor no va a entregar
 * nada". Cerrar es "ya recibí lo que iba a recibir, el resto no llega y no
 * lo voy a seguir esperando" — el caso del faltante que el proveedor no
 * repone. Contablemente ninguna de las dos hace nada; la diferencia es de
 * trazabilidad, y por eso son dos estados y no uno.
 */
class PurchaseOrderService
{
    /**
     * @param  PurchaseOrderLineInput[]  $lines
     */
    public function place(
        Company $company,
        int $businessPartnerId,
        \DateTimeInterface $orderDate,
        array $lines,
        ?\DateTimeInterface $expectedDate = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): PurchaseOrder {
        if (empty($lines)) {
            throw new InvalidPurchaseOrderException('Una orden de compra requiere al menos una línea.');
        }

        return DB::transaction(function () use ($company, $businessPartnerId, $orderDate, $lines, $expectedDate, $description, $createdBy) {
            $supplier = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($businessPartnerId);

            if (! $supplier) {
                throw new InvalidPurchaseOrderException("El socio de negocio id {$businessPartnerId} no existe en la compañía.");
            }

            if (! in_array($supplier->type, ['supplier', 'both'], true)) {
                throw new InvalidPurchaseOrderException(
                    "El socio de negocio {$supplier->code} ({$supplier->name}) no está registrado como proveedor."
                );
            }

            [$items, $warehouses] = $this->resolveCatalogs($company, $lines);

            $order = PurchaseOrder::create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'business_partner_id' => $supplier->id,
                'order_date' => $orderDate->format('Y-m-d'),
                'expected_date' => $expectedDate?->format('Y-m-d'),
                'status' => 'open',
                'description' => $description,
                'created_by' => $createdBy,
            ]);

            foreach (array_values($lines) as $index => $line) {
                $item = $items->get($line->itemId)
                    ?? throw new InvalidPurchaseOrderException("El artículo id {$line->itemId} no existe en la compañía.");

                $warehouse = $warehouses->get($line->warehouseId)
                    ?? throw new InvalidPurchaseOrderException("El almacén id {$line->warehouseId} no existe en la compañía.");

                // Un servicio no lleva kardex, así que tampoco puede estar
                // "en camino": no hay existencia que esperar. Misma regla que
                // rechaza moverlo en inventario.
                if (! $item->is_inventory_item) {
                    throw new InvalidPurchaseOrderException(
                        "El artículo {$item->code} está marcado como servicio; no lleva kardex y no puede ordenarse a inventario."
                    );
                }

                if (! $item->is_purchase_item) {
                    throw new InvalidPurchaseOrderException(
                        "El artículo {$item->code} no está marcado como comprable."
                    );
                }

                if ($warehouse->status !== 'active') {
                    throw new InvalidPurchaseOrderException("El almacén {$warehouse->code} está inactivo; no admite órdenes.");
                }

                PurchaseOrderLine::create([
                    'purchase_order_id' => $order->id,
                    'line_number' => $index + 1,
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $line->quantity,
                    'quantity_received' => '0.000000',
                    'unit_cost_local' => $line->unitCostLocal,
                    'description' => $line->description,
                ]);

                $this->addOrdered($item->id, $warehouse->id, $line->quantity);
            }

            return $order->load('lines');
        });
    }

    /**
     * Descarga de la orden lo que una recepción trajo. Se llama DESDE el
     * flujo de entrada por compra, no al revés: el dueño del kardex sigue
     * siendo PostStockMovementService y esto solo ajusta el pendiente.
     *
     * @param  array<int, array{item_id: int, warehouse_id: int, quantity: string|float}>  $received
     */
    public function receive(PurchaseOrder $order, array $received): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $received) {
            $order = PurchaseOrder::withoutGlobalScope(CompanyScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! in_array($order->status, PurchaseOrder::PENDING_STATUSES, true)) {
                throw new InvalidPurchaseOrderException(
                    "La orden de compra {$order->number} está '{$order->status}' y ya no espera mercancía."
                );
            }

            $lines = PurchaseOrderLine::where('purchase_order_id', $order->id)->get();

            foreach ($received as $row) {
                $quantity = number_format((float) $row['quantity'], 6, '.', '');

                if (bccomp($quantity, '0.000000', 6) <= 0) {
                    continue;
                }

                $line = $lines->first(fn (PurchaseOrderLine $l) => $l->item_id === (int) $row['item_id']
                    && $l->warehouse_id === (int) $row['warehouse_id']
                    && bccomp($l->pending(), '0.000000', 6) > 0);

                if (! $line) {
                    throw new InvalidPurchaseOrderException(
                        "La orden {$order->number} no tiene pendiente el artículo id {$row['item_id']} para ese almacén."
                    );
                }

                // Recibir de más se topa a lo pendiente, no se rechaza: que
                // un proveedor mande unidades de sobra es un hecho real y la
                // mercancía igual entró al kardex. Lo que no puede pasar es
                // que "en camino" quede negativo.
                $applied = bccomp($quantity, $line->pending(), 6) > 0 ? $line->pending() : $quantity;

                $line->update([
                    'quantity_received' => bcadd((string) $line->quantity_received, $applied, 6),
                ]);

                $this->addOrdered($line->item_id, $line->warehouse_id, bcmul($applied, '-1', 6));
            }

            $order->refresh()->load('lines');

            $order->update([
                'status' => bccomp($order->pendingQuantity(), '0.000000', 6) <= 0
                    ? 'received'
                    : 'partially_received',
            ]);

            return $order->refresh()->load('lines');
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return $this->release($order, 'cancelled');
    }

    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return $this->release($order, 'closed');
    }

    /**
     * Suelta lo que faltaba por recibir y deja la orden en su estado final.
     * Cancelar y cerrar comparten el efecto porque contablemente ninguno hace
     * nada: la diferencia es qué le pasó a la orden, y eso se guarda.
     */
    private function release(PurchaseOrder $order, string $status): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $status) {
            $order = PurchaseOrder::withoutGlobalScope(CompanyScope::class)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! in_array($order->status, PurchaseOrder::PENDING_STATUSES, true)) {
                throw new InvalidPurchaseOrderException(
                    "La orden de compra {$order->number} ya está '{$order->status}'."
                );
            }

            if ($status === 'cancelled' && $order->status === 'partially_received') {
                throw new InvalidPurchaseOrderException(
                    "La orden {$order->number} ya recibió mercancía; no se puede cancelar. Cerrala con saldo pendiente."
                );
            }

            foreach (PurchaseOrderLine::where('purchase_order_id', $order->id)->get() as $line) {
                $pending = $line->pending();

                if (bccomp($pending, '0.000000', 6) > 0) {
                    $this->addOrdered($line->item_id, $line->warehouse_id, bcmul($pending, '-1', 6));
                }
            }

            $order->update(['status' => $status]);

            return $order->refresh()->load('lines');
        });
    }

    /**
     * Único punto que escribe item_warehouses.ordered. El lock es por la
     * misma razón que en el resto del módulo: sin él dos órdenes
     * concurrentes del mismo artículo se pisan el acumulado.
     */
    private function addOrdered(int $itemId, int $warehouseId, string $delta): void
    {
        $stock = ItemWarehouse::lockForUpdate()->firstOrCreate(
            ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
            ['on_hand' => '0.000000', 'reserved' => '0.000000', 'ordered' => '0.000000'],
        );

        $next = bcadd((string) $stock->ordered, $delta, 6);

        // Guarda contra el negativo: si alguna vía descontara de más, es
        // preferible quedar en cero que arrastrar un pendiente imposible.
        $stock->update([
            'ordered' => bccomp($next, '0.000000', 6) < 0 ? '0.000000' : $next,
        ]);
    }

    /**
     * @param  PurchaseOrderLineInput[]  $lines
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function resolveCatalogs(Company $company, array $lines): array
    {
        $itemIds = array_values(array_unique(array_map(fn (PurchaseOrderLineInput $l) => $l->itemId, $lines)));
        $warehouseIds = array_values(array_unique(array_map(fn (PurchaseOrderLineInput $l) => $l->warehouseId, $lines)));

        $items = Item::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $warehouses = Warehouse::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('id', $warehouseIds)
            ->get()
            ->keyBy('id');

        return [$items, $warehouses];
    }

    private function nextNumber(Company $company): string
    {
        $last = PurchaseOrder::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->lockForUpdate()
            ->max('number');

        return str_pad((string) (((int) $last) + 1), 8, '0', STR_PAD_LEFT);
    }
}
