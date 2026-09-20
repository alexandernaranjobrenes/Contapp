<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\DataTransferObjects\SalesOrderLineInput;
use App\Domains\Billing\Exceptions\InvalidSalesOrderException;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dueño único de la reserva de stock (item_warehouses.reserved), igual que
 * PostStockMovementService lo es de la existencia. Tres momentos la mueven:
 *
 *   place()   aparta mercancía        reserved += lo pedido
 *   consume() la libera al facturar   reserved -= lo facturado
 *   cancel()  la libera sin facturar  reserved -= lo que quedaba
 *
 * Una orden NO genera asiento: no es un hecho económico todavía. Lo único que
 * cambia en el sistema es cuánta mercancía queda libre para comprometerle a
 * otro cliente.
 */
class SalesOrderService
{
    /**
     * @param  SalesOrderLineInput[]  $lines
     */
    public function place(
        Company $company,
        int $businessPartnerId,
        \DateTimeInterface $orderDate,
        array $lines,
        ?\DateTimeInterface $deliveryDate = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): SalesOrder {
        if (empty($lines)) {
            throw new InvalidSalesOrderException('Una orden de pedido requiere al menos una línea.');
        }

        return DB::transaction(function () use ($company, $businessPartnerId, $orderDate, $lines, $deliveryDate, $description, $createdBy) {
            $customer = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($businessPartnerId);

            if (! $customer) {
                throw new InvalidSalesOrderException("El socio de negocio id {$businessPartnerId} no existe en la compañía.");
            }

            if (! in_array($customer->type, ['client', 'both'], true)) {
                throw new InvalidSalesOrderException(
                    "El socio de negocio {$customer->code} ({$customer->name}) no está registrado como cliente."
                );
            }

            [$items, $warehouses, $stock] = $this->lockStock($company, $lines);

            $order = SalesOrder::create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'business_partner_id' => $customer->id,
                'order_date' => $orderDate->format('Y-m-d'),
                'delivery_date' => $deliveryDate?->format('Y-m-d'),
                'status' => 'open',
                'description' => $description,
                'created_by' => $createdBy,
            ]);

            foreach (array_values($lines) as $index => $line) {
                $item = $items->get($line->itemId)
                    ?? throw new InvalidSalesOrderException("El artículo id {$line->itemId} no existe en la compañía.");

                $warehouse = $warehouses->get($line->warehouseId)
                    ?? throw new InvalidSalesOrderException("El almacén id {$line->warehouseId} no existe en la compañía.");

                if (! $item->is_inventory_item) {
                    throw new InvalidSalesOrderException(
                        "El artículo {$item->code} es un servicio: no lleva existencia y no se puede reservar."
                    );
                }

                $key = $item->id.':'.$warehouse->id;
                $row = $stock[$key] ?? null;

                $onHand = $row ? (string) $row->on_hand : '0.000000';
                $reserved = $row ? (string) $row->reserved : '0.000000';
                $free = bcsub($onHand, $reserved, 6);

                // Apartar lo que no existe no es apartar: sería prometerle al
                // cliente mercancía que no hay. Misma regla que la existencia
                // negativa, que el módulo tampoco acepta.
                if (bccomp($line->quantity, $free, 6) > 0) {
                    throw new InvalidSalesOrderException(
                        "No hay suficiente disponible de {$item->code} en {$warehouse->code}: ".
                        'se piden '.$this->trim($line->quantity).', hay '.$this->trim($onHand).
                        ' y ya están apartadas '.$this->trim($reserved).'.'
                    );
                }

                $order->lines()->create([
                    'line_number' => $index + 1,
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $line->quantity,
                    'quantity_invoiced' => '0',
                    'unit_price' => $line->unitPrice ?? '0',
                    'description' => $line->description,
                ]);

                $this->addReservation($item->id, $warehouse->id, $line->quantity);
                $stock = $this->reloadStock($stock, $key, $item->id, $warehouse->id);
            }

            return $order->load('lines');
        });
    }

    /**
     * Libera la reserva de lo que se está facturando. Corre ANTES de que el
     * comprobante rebaje la existencia: si no, la orden se bloquearía a sí
     * misma —su propia reserva haría ver la mercancía como no disponible.
     *
     * @param  array<int, array{item_id: int, warehouse_id: int, quantity: string}>  $invoiced
     */
    public function consume(Company $company, SalesOrder $order, array $invoiced): SalesOrder
    {
        $order = SalesOrder::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->lockForUpdate()
            ->findOrFail($order->id);

        if ($order->status !== 'open') {
            throw new InvalidSalesOrderException(
                'La orden de pedido ya fue '.($order->status === 'invoiced' ? 'facturada' : 'cancelada').'.'
            );
        }

        $lines = $order->lines()->get();

        foreach ($invoiced as $entry) {
            $remaining = $entry['quantity'];

            // Una misma combinación artículo/almacén puede venir en varias
            // líneas de la orden; se consumen en orden hasta cubrir lo
            // facturado.
            foreach ($lines as $line) {
                if (bccomp($remaining, '0.000000', 6) <= 0) {
                    break;
                }

                if ($line->item_id !== $entry['item_id'] || $line->warehouse_id !== $entry['warehouse_id']) {
                    continue;
                }

                $take = bccomp($line->pending(), $remaining, 6) >= 0 ? $remaining : $line->pending();

                if (bccomp($take, '0.000000', 6) <= 0) {
                    continue;
                }

                $line->update(['quantity_invoiced' => bcadd((string) $line->quantity_invoiced, $take, 6)]);
                $this->addReservation($line->item_id, $line->warehouse_id, bcmul($take, '-1', 6));

                $remaining = bcsub($remaining, $take, 6);
            }

            if (bccomp($remaining, '0.000000', 6) > 0) {
                throw new InvalidSalesOrderException(
                    'La factura excede lo pendiente en la orden de pedido: sobran '.
                    $this->trim($remaining).' unidades sin respaldo en el pedido.'
                );
            }
        }

        $pending = $order->lines()->get()->sum(fn ($line) => (float) $line->pending());

        if ($pending <= 0) {
            $order->update(['status' => 'invoiced']);
        }

        return $order->fresh('lines');
    }

    /**
     * Cancela una orden abierta: suelta todo lo que seguía apartado. Lo ya
     * facturado no se toca — esa mercancía salió y tiene su comprobante.
     */
    public function cancel(Company $company, SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return DB::transaction(function () use ($company, $order, $reason) {
            $order = SalesOrder::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->status !== 'open') {
                throw new InvalidSalesOrderException(
                    'Solo una orden abierta se puede cancelar; esta ya fue '.
                    ($order->status === 'invoiced' ? 'facturada' : 'cancelada').'.'
                );
            }

            foreach ($order->lines()->get() as $line) {
                $pending = $line->pending();

                if (bccomp($pending, '0.000000', 6) > 0) {
                    $this->addReservation($line->item_id, $line->warehouse_id, bcmul($pending, '-1', 6));
                }
            }

            $order->update([
                'status' => 'cancelled',
                'description' => $reason ?? $order->description,
            ]);

            return $order->fresh('lines');
        });
    }

    /**
     * @param  SalesOrderLineInput[]  $lines
     * @return array{0: Collection, 1: Collection, 2: array<string, ItemWarehouse>}
     */
    private function lockStock(Company $company, array $lines): array
    {
        $itemIds = array_values(array_unique(array_map(fn (SalesOrderLineInput $l) => $l->itemId, $lines)));
        $warehouseIds = array_values(array_unique(array_map(fn (SalesOrderLineInput $l) => $l->warehouseId, $lines)));

        // Mismo orden de bloqueo que PostStockMovementService::post() —
        // artículos por id y después sus existencias— para que una orden y
        // una venta concurrentes no se traben entre sí.
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

        $stock = [];

        foreach (ItemWarehouse::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
            $stock[$row->item_id.':'.$row->warehouse_id] = $row;
        }

        return [$items, $warehouses, $stock];
    }

    private function addReservation(int $itemId, int $warehouseId, string $delta): void
    {
        $row = ItemWarehouse::firstOrCreate(
            ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
            ['on_hand' => '0', 'reserved' => '0'],
        );

        $row->update(['reserved' => bcadd((string) $row->reserved, $delta, 6)]);
    }

    /**
     * @param  array<string, ItemWarehouse>  $stock
     * @return array<string, ItemWarehouse>
     */
    private function reloadStock(array $stock, string $key, int $itemId, int $warehouseId): array
    {
        $stock[$key] = ItemWarehouse::where('item_id', $itemId)->where('warehouse_id', $warehouseId)->first();

        return $stock;
    }

    /**
     * Consecutivo interno por compañía. No es fiscal: una orden no se declara,
     * así que no pasa por document_types ni por series de Hacienda.
     */
    private function nextNumber(Company $company): string
    {
        $last = SalesOrder::withoutGlobalScope(CompanyScope::class)
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
