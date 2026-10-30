<?php

namespace App\Domains\Core\Dashboard;

/**
 * Los elementos que se pueden poner en el Panel (CLAUDE.md secc. 44), en el
 * orden en que los ofrece el catálogo. Un test revisa que cada uno declare
 * pantallas que existen en ScreenCatalog y que las claves no se repitan.
 */
final class DashboardWidgetCatalog
{
    /** Lo más que lleva un Panel: más que esto lo recarga. */
    public const MAX_WIDGETS = 12;

    /** El Panel de una compañía que nadie personalizó todavía. */
    public const DEFAULT_LAYOUT = [
        'month_result',
        'draft_entries',
        'exchange_rate',
        'income_expenses',
        'receivables_aging',
        'sales_by_month',
        'recent_entries',
        'below_minimum',
    ];

    private const WIDGETS = [
        Widgets\MonthResultWidget::class,
        Widgets\IncomeExpensesWidget::class,
        Widgets\DraftEntriesWidget::class,
        Widgets\RecentEntriesWidget::class,
        Widgets\ExchangeRateWidget::class,
        Widgets\CashBalancesWidget::class,
        Widgets\ReceivablesAgingWidget::class,
        Widgets\PayablesAgingWidget::class,
        Widgets\TopDebtorsWidget::class,
        Widgets\SalesByMonthWidget::class,
        Widgets\ElectronicDocumentsWidget::class,
        Widgets\BelowMinimumWidget::class,
        Widgets\InventoryByWarehouseWidget::class,
        Widgets\PayrollCostWidget::class,
        Widgets\ContiUsageWidget::class,
    ];

    /** @var array<string, DashboardWidget>|null */
    private static ?array $widgets = null;

    /** @return array<string, DashboardWidget> */
    public static function all(): array
    {
        if (self::$widgets !== null) {
            return self::$widgets;
        }

        $widgets = [];
        foreach (self::WIDGETS as $class) {
            $widget = new $class;
            $widgets[$widget->key()] = $widget;
        }

        return self::$widgets = $widgets;
    }

    public static function find(string $key): ?DashboardWidget
    {
        return self::all()[$key] ?? null;
    }
}
