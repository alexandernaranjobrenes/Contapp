<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\InventoryValuationRow;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InventoryValuationService;

/**
 * El inventario valorizado de hoy por almacén: las existencias valorizadas
 * (InventoryValuationService), con su mismo cálculo desde el kardex, sumadas
 * por almacén. El total coincide con el reporte.
 */
class InventoryByWarehouseWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'inventory_by_warehouse';
    }

    public function title(): string
    {
        return 'Inventario por almacén';
    }

    public function description(): string
    {
        return 'Lo que vale el inventario de cada almacén hoy, al costo, en moneda local.';
    }

    public function section(): string
    {
        return 'inventory';
    }

    public function kind(): string
    {
        return 'bars';
    }

    public function screens(): array
    {
        return ['reports.inventory_valuation'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('reports.inventory-valuation.index');
    }

    public function data(DashboardContext $context): array
    {
        $valuation = app(InventoryValuationService::class)->build($context->company, $context->today->toDateString());

        $names = Warehouse::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $context->company->id)
            ->pluck('name', 'id');

        $rows = collect($valuation->rows)
            ->groupBy(fn (InventoryValuationRow $row) => $row->warehouseId)
            ->map(function ($rows, $warehouseId) use ($names) {
                $value = $rows->reduce(fn (string $sum, InventoryValuationRow $row) => bcadd($sum, $row->valueLocal, 2), '0.00');

                return [
                    'label' => $names[$warehouseId] ?? $rows->first()->warehouseCode,
                    'sublabel' => $rows->first()->warehouseCode,
                    'value' => DashboardContext::money($value),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'rows' => $rows,
            'total' => ['label' => 'Total', 'value' => DashboardContext::money($valuation->totalValueLocal)],
            'empty' => 'No hay existencias valorizadas.',
        ];
    }
}
