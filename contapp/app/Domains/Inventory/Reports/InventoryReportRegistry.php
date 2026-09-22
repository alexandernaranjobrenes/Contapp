<?php

namespace App\Domains\Inventory\Reports;

/**
 * El índice de reportes de inventario.
 *
 * Agregar uno es escribir su clase y sumarla a esta lista. No hay que tocar
 * el controlador, ni la pantalla, ni el exportador, ni el PDF: los cuatro
 * trabajan contra el contrato InventoryReport.
 *
 * En código y no en tabla, por la misma razón que ReportCatalog: un reporte
 * es código, y un registro en base de datos que apunte a una clase que no
 * existe es un error que nadie ve hasta que alguien lo abre.
 */
class InventoryReportRegistry
{
    /** @var array<string, class-string<InventoryReport>> */
    private const REPORTS = [
        'item-catalog' => ItemCatalogReport::class,
        'stock-on-hand' => StockOnHandReport::class,
        'open-items' => OpenItemsReport::class,
        'item-movements' => ItemMovementsReport::class,
        'price-list' => PriceListReport::class,
        'item-profitability' => ItemProfitabilityReport::class,
        'turnover-kpi' => TurnoverKpiReport::class,
        'abc-analysis' => AbcAnalysisReport::class,
    ];

    /**
     * El orden en que se agrupan en el índice: de lo más consultado a lo más
     * analítico.
     *
     * @var string[]
     */
    public const GROUP_ORDER = ['Catálogo', 'Existencias', 'Compromisos', 'Movimiento', 'Rentabilidad', 'Indicadores'];

    /**
     * @return InventoryReport[]
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $code): ?InventoryReport
    {
        $class = self::REPORTS[$code] ?? null;

        return $class === null ? null : app($class);
    }

    public function has(string $code): bool
    {
        return isset(self::REPORTS[$code]);
    }

    /**
     * Los reportes agrupados y en el orden del índice.
     *
     * @return array<string, InventoryReport[]>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach (self::GROUP_ORDER as $group) {
            $grouped[$group] = [];
        }

        foreach ($this->all() as $report) {
            $grouped[$report->group()][] = $report;
        }

        return array_filter($grouped, fn (array $reports) => $reports !== []);
    }
}
