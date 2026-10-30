<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Inventory\Services\ReorderSuggestionService;

/**
 * Los artículos bajo su mínimo, lo más descubierto primero: la sugerencia de
 * compra (ReorderSuggestionService), con su mismo cálculo de lo disponible.
 */
class BelowMinimumWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'below_minimum';
    }

    public function title(): string
    {
        return 'Artículos bajo el mínimo';
    }

    public function description(): string
    {
        return 'Los artículos con menos de su existencia mínima en algún almacén, y cuánto conviene pedir.';
    }

    public function section(): string
    {
        return 'inventory';
    }

    public function kind(): string
    {
        return 'list';
    }

    public function screens(): array
    {
        return ['inventory.reorder'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('reorder.index');
    }

    public function data(DashboardContext $context): array
    {
        $suggestions = app(ReorderSuggestionService::class)->build($context->company)
            ->sortBy('coverage')
            ->values();

        return [
            'count' => $suggestions->count(),
            'rows' => $suggestions->take(5)->map(fn (array $s) => [
                'title' => "{$s['item_code']} — {$s['item_name']}",
                'subtitle' => "{$s['warehouse_code']}: hay ".self::quantity($s['available']).' de un mínimo de '.self::quantity($s['minimum_stock']).($s['uom'] ? " {$s['uom']}" : ''),
                'meta' => 'Pedir '.self::quantity($s['suggested_quantity']),
                'href' => route('reorder.index'),
            ])->all(),
            'more' => max(0, $suggestions->count() - 5),
            'empty' => 'Ningún artículo está bajo su mínimo.',
            'emptyGood' => true,
        ];
    }

    /** Una cantidad como la escribe Pivox: «1.250» o «12,5». */
    private static function quantity(string $value): string
    {
        $formatted = number_format((float) $value, 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
