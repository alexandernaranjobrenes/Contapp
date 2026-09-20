<?php

namespace App\Domains\Reporting\Support;

/**
 * Esquema de parámetros de los 6 reportes existentes, EN CÓDIGO — no hay
 * tabla "reports"/"reportes_parametros" (el motor metadata-driven de
 * CLAUDE.md secc. 4 se descartó explícitamente el 2026-08-27). Única fuente
 * de verdad tanto para validar un SavedReport nuevo como para detectar si
 * uno guardado quedó desactualizado frente al reporte real (ver
 * App\Domains\Reporting\Services\SavedReportService).
 *
 * Refleja la validación inline que cada controlador de reporte ya hace en
 * su propio validateFilters() — si un controlador cambia sus parámetros,
 * esta definición debe actualizarse a mano (no hay reflexión automática:
 * ninguno de los 6 expone un FormRequest ni un array de reglas público).
 */
final class ReportCatalog
{
    /**
     * @return array<string, array{label: string, route_index: string, route_export: string, route_export_pdf: string, parameters: array<string, array{type: string, required: bool, options?: array<int, string>}>}>
     */
    public static function definitions(): array
    {
        return [
            'inventory-aging' => [
                'label' => 'Antigüedad de inventario',
                'route_index' => 'reports.inventory-aging.index',
                'route_export' => 'reports.inventory-aging.export',
                'route_export_pdf' => 'reports.inventory-aging.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                    'buckets' => ['type' => 'text', 'required' => false],
                    'warehouse_id' => ['type' => 'integer', 'required' => false],
                    'item_group_id' => ['type' => 'integer', 'required' => false],
                ],
            ],
            'inventory-valuation' => [
                'label' => 'Existencias valorizadas',
                'route_index' => 'reports.inventory-valuation.index',
                'route_export' => 'reports.inventory-valuation.export',
                'route_export_pdf' => 'reports.inventory-valuation.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                    'warehouse_id' => ['type' => 'integer', 'required' => false],
                    'item_group_id' => ['type' => 'integer', 'required' => false],
                    'hide_zero' => ['type' => 'boolean', 'required' => false],
                ],
            ],
            'trial-balance' => [
                'label' => 'Balance de comprobación',
                'route_index' => 'reports.trial-balance.index',
                'route_export' => 'reports.trial-balance.export',
                'route_export_pdf' => 'reports.trial-balance.export-pdf',
                'parameters' => [
                    'from' => ['type' => 'date', 'required' => false],
                    'to' => ['type' => 'date', 'required' => false],
                    'hide_zero' => ['type' => 'boolean', 'required' => false],
                ],
            ],
            'income-statement' => [
                'label' => 'Estado de resultados',
                'route_index' => 'reports.income-statement.index',
                'route_export' => 'reports.income-statement.export',
                'route_export_pdf' => 'reports.income-statement.export-pdf',
                'parameters' => [
                    'from' => ['type' => 'date', 'required' => true],
                    'to' => ['type' => 'date', 'required' => true],
                    'hide_zero' => ['type' => 'boolean', 'required' => false],
                ],
            ],
            'balance-sheet' => [
                'label' => 'Balance general',
                'route_index' => 'reports.balance-sheet.index',
                'route_export' => 'reports.balance-sheet.export',
                'route_export_pdf' => 'reports.balance-sheet.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                ],
            ],
            'aging' => [
                'label' => 'Antigüedad de saldos',
                'route_index' => 'reports.aging.index',
                'route_export' => 'reports.aging.export',
                'route_export_pdf' => 'reports.aging.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                    'partner_type' => ['type' => 'enum', 'required' => true, 'options' => ['both', 'client', 'supplier']],
                    // Cortes de días personalizados (ej. "15,45,90,180"), en
                    // vez del estándar 30/60/90 — texto libre, opcional (ver
                    // DayBucketScheme/AgingController::validateFilters).
                    'buckets' => ['type' => 'text', 'required' => false],
                ],
            ],
            'cash-flow-projection' => [
                'label' => 'Proyección de cobros y pagos',
                'route_index' => 'reports.cash-flow-projection.index',
                'route_export' => 'reports.cash-flow-projection.export',
                'route_export_pdf' => 'reports.cash-flow-projection.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                    'buckets' => ['type' => 'text', 'required' => false],
                ],
            ],
            'multi-company-comparison' => [
                'label' => 'Comparativo de empresas',
                'route_index' => 'reports.multi-company-comparison.index',
                'route_export' => 'reports.multi-company-comparison.export',
                'route_export_pdf' => 'reports.multi-company-comparison.export-pdf',
                'parameters' => [
                    'as_of' => ['type' => 'date', 'required' => true],
                    'from' => ['type' => 'date', 'required' => true],
                    'to' => ['type' => 'date', 'required' => true],
                ],
            ],
            'cost-center' => [
                'label' => 'Auxiliar por centro de costo',
                'route_index' => 'reports.cost-center.index',
                'route_export' => 'reports.cost-center.export',
                'route_export_pdf' => 'reports.cost-center.export-pdf',
                'parameters' => [
                    'from' => ['type' => 'date', 'required' => false],
                    'to' => ['type' => 'date', 'required' => false],
                ],
            ],
            'cost-allocation-rule' => [
                'label' => 'Normas de reparto: distribución real vs. definida',
                'route_index' => 'reports.cost-allocation-rule.index',
                'route_export' => 'reports.cost-allocation-rule.export',
                'route_export_pdf' => 'reports.cost-allocation-rule.export-pdf',
                'parameters' => [
                    'from' => ['type' => 'date', 'required' => false],
                    'to' => ['type' => 'date', 'required' => false],
                ],
            ],
            'period-comparison' => [
                'label' => 'Comparativo entre dos periodos',
                'route_index' => 'reports.period-comparison.index',
                'route_export' => 'reports.period-comparison.export',
                'route_export_pdf' => 'reports.period-comparison.export-pdf',
                'parameters' => [
                    'from_1' => ['type' => 'date', 'required' => true],
                    'to_1' => ['type' => 'date', 'required' => true],
                    'from_2' => ['type' => 'date', 'required' => true],
                    'to_2' => ['type' => 'date', 'required' => true],
                ],
            ],
        ];
    }

    public static function for(string $reportCode): ?array
    {
        return self::definitions()[$reportCode] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }
}
