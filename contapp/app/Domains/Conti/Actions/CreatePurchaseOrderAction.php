<?php

namespace App\Domains\Conti\Actions;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PurchaseOrderService;
use App\Models\User;

/** Una orden de compra, como en Órdenes de compra (PurchaseOrderController::store). */
final class CreatePurchaseOrderAction extends BaseContiAction
{
    public function key(): string
    {
        return 'crear_orden_compra';
    }

    public function label(): string
    {
        return 'Crear una orden de compra';
    }

    public function description(): string
    {
        return 'Registra una orden de compra a un proveedor. No genera asiento: declara lo que viene en camino. El costo es el pactado e informativo.';
    }

    public function screen(): string
    {
        return 'inventory.purchase_orders';
    }

    public function fields(): array
    {
        return [
            'proveedor' => 'código del proveedor',
            'fecha' => 'AAAA-MM-DD; por defecto hoy',
            'fecha_esperada' => 'AAAA-MM-DD (opcional)',
            'descripcion' => 'opcional',
            'lineas' => 'lista de {articulo: código, cantidad, almacen: código (por defecto el predeterminado), costo: costo pactado por unidad (opcional), descripcion}',
        ];
    }

    public function form(Company $company): array
    {
        return [
            $this->field('proveedor', 'Proveedor', 'buscar', ['requerido' => true, 'fuente' => 'socios']),
            $this->field('fecha', 'Fecha', 'fecha', ['requerido' => true, 'defecto' => 'hoy']),
            $this->field('fecha_esperada', 'Fecha esperada', 'fecha'),
            $this->field('descripcion', 'Descripción', 'texto'),
            $this->field('lineas', 'Artículos', 'lineas', ['requerido' => true, 'minimo' => 1, 'columnas' => [
                $this->field('articulo', 'Artículo', 'buscar', ['requerido' => true, 'fuente' => 'articulos']),
                $this->field('cantidad', 'Cantidad', 'numero', ['requerido' => true]),
                $this->field('costo', 'Costo por unidad', 'monto'),
                $this->field('almacen', 'Almacén', 'buscar', ['fuente' => 'almacenes', 'defecto' => Warehouse::where('is_default', true)->value('code'), 'ayuda' => 'De entrada, el predeterminado.']),
                $this->field('descripcion', 'Descripción', 'texto', ['avanzado' => true]),
            ]]),
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['fecha'] = ($input['fecha'] ?? '') !== '' ? $input['fecha'] : now()->format('Y-m-d');

        $this->validate($input, [
            'proveedor' => ['required', 'string'],
            'fecha' => ['required', 'date'],
            'fecha_esperada' => ['nullable', 'date'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'lineas' => ['required', 'array', 'min:1', 'max:200'],
            'lineas.*.articulo' => ['required', 'string'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.almacen' => ['nullable', 'string'],
            'lineas.*.costo' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $supplier = $this->byCode(BusinessPartner::class, $input['proveedor'], 'proveedor', 'un socio');

        if (! $supplier->isSupplier()) {
            $this->fail('proveedor', "{$supplier->code} {$supplier->name} no es proveedor.");
        }

        $default = Warehouse::where('is_default', true)->first();
        $lines = [];
        $rows = [];
        $total = '0';

        foreach (array_values($input['lineas']) as $i => $line) {
            $item = $this->byCode(Item::class, $line['articulo'], "lineas.{$i}.articulo", 'un artículo');
            $warehouse = ($line['almacen'] ?? '') !== ''
                ? $this->byCode(Warehouse::class, $line['almacen'], "lineas.{$i}.almacen", 'un almacén')
                : ($default ?? $this->fail("lineas.{$i}.almacen", 'Indicá el almacén: la compañía no tiene uno predeterminado.'));
            $quantity = (string) $line['cantidad'];
            $cost = ($line['costo'] ?? '') !== '' ? bcadd((string) $line['costo'], '0', 2) : '0.00';
            $total = bcadd($total, bcmul($quantity, $cost, 2), 2);

            $lines[] = [
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'unit_cost_local' => $cost,
                'description' => ($line['descripcion'] ?? '') !== '' ? $line['descripcion'] : null,
            ];
            $rows[] = ["{$item->code} {$item->name}", "{$warehouse->code} {$warehouse->name}", $quantity, $this->money($cost)];
        }

        return new PreparedAction(
            title: "Orden de compra a {$supplier->code} {$supplier->name}",
            items: $this->items([
                'Proveedor' => "{$supplier->code} {$supplier->name}",
                'Fecha' => date('Y-m-d', strtotime($input['fecha'])),
                'Fecha esperada' => ($input['fecha_esperada'] ?? '') !== '' ? date('Y-m-d', strtotime($input['fecha_esperada'])) : null,
                'Descripción' => $input['descripcion'] ?? null,
                'Total pactado' => $this->money($total),
            ]),
            payload: [
                'business_partner_id' => $supplier->id,
                'order_date' => date('Y-m-d', strtotime($input['fecha'])),
                'expected_date' => ($input['fecha_esperada'] ?? '') !== '' ? date('Y-m-d', strtotime($input['fecha_esperada'])) : null,
                'description' => ($input['descripcion'] ?? '') !== '' ? $input['descripcion'] : null,
                'lines' => $lines,
            ],
            input: $input,
            table: ['columnas' => ['Artículo', 'Almacén', 'Cantidad', 'Costo pactado'], 'filas' => $rows],
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $p = $prepared->payload;

        $order = app(PurchaseOrderService::class)->place(
            $company,
            $p['business_partner_id'],
            new \DateTimeImmutable($p['order_date']),
            array_map(fn (array $l) => new PurchaseOrderLineInput(
                itemId: $l['item_id'],
                warehouseId: $l['warehouse_id'],
                quantity: $l['quantity'],
                unitCostLocal: $l['unit_cost_local'],
                description: $l['description'],
            ), $p['lines']),
            $p['expected_date'] ? new \DateTimeImmutable($p['expected_date']) : null,
            $p['description'],
            $user->id,
        );

        return new ActionResult("Orden de compra {$order->number} creada.", route('purchase-orders.show', $order->id), 'Ver la orden de compra');
    }
}
