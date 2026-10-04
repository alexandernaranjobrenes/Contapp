<?php

namespace App\Domains\Core\Support;

use Illuminate\Support\Str;

/**
 * Las pantallas del menú lateral sobre las que se dan permisos: cada opción
 * de cada sección de AppLayout.vue (Registros, Saldos iniciales, Movimientos,
 * Comprobantes…), con su sección, su categoría y las rutas que le pertenecen.
 * Ver ScreenAccessService.
 *
 * - module: el módulo de permisos del grupo de rutas donde viven sus rutas
 *   (`module-access:{module},…` en routes/web.php). Un permiso por módulo, el
 *   esquema anterior, vale para todas las pantallas de ese módulo.
 * - routes: nombres de ruta, exactos o con «*» al final. Si una ruta calza con
 *   un nombre exacto de una pantalla y con el comodín de otra, gana el exacto
 *   («sales-documents.create» es Nueva factura; el resto de
 *   «sales-documents.*», Comprobantes).
 * - read_only: pantallas que solo consultan (los reportes). Su nivel máximo
 *   es Lectura.
 *
 * Administración y Mi cuenta no están: dependen del rol, no de un permiso.
 * Tampoco el Panel, que es de todos.
 *
 * Tres tests mantienen esto al día (ScreenCatalogTest): cada ruta con
 * `module-access` pertenece a una pantalla o está en SHARED_ROUTES; cada
 * pantalla tiene al menos una ruta; y las pantallas son las mismas que las
 * del menú de AppLayout.vue (su `screen:`).
 */
final class ScreenCatalog
{
    /**
     * Rutas que usan varias pantallas para traer datos (el libro mayor de una
     * cuenta, los precios de un cliente al facturar…). No son de ninguna: las
     * cuida solo el permiso de su módulo, que tiene quien tenga cualquier
     * pantalla de ese módulo.
     */
    public const SHARED_ROUTES = [
        'ledger.*',
        'price-lists.for-customer',
        'item-lots.options',
        'item-lots.trace',
        'bills-of-materials.explode',
    ];

    /** @var array<string, array>|null */
    private static ?array $screens = null;

    /** @var array<string, ?string> */
    private static array $routeCache = [];

    /**
     * Las secciones del menú, en orden, cada una con sus pantallas.
     *
     * @return list<array{key: string, label: string, screens: list<array>}>
     */
    public static function sections(): array
    {
        $r = fn (string $key, string $label, string $group, string $module, array $routes, bool $readOnly = false) => compact('key', 'label', 'group', 'module', 'routes') + ['read_only' => $readOnly];
        // Los reportes solo consultan.
        $report = fn (string $key, string $label, string $routePrefix, string $module = 'reports') => $r($key, $label, 'Reportes', $module, [$routePrefix.'.*'], true);

        return [
            ['key' => 'accounting', 'label' => 'Contabilidad', 'screens' => [
                $r('accounting.journal_entries', 'Registros', 'Operación', 'accounting', ['journal-entries.*']),
                $r('accounting.journal_entry_schedules', 'Registros programados', 'Operación', 'accounting', ['journal-entry-schedules.*']),
                $r('accounting.opening_balance', 'Saldos iniciales', 'Operación', 'accounting', ['opening-balance.*']),
                $r('accounting.period_close', 'Cierre de períodos', 'Operación', 'accounting', ['period-close.*']),
                $report('reports.trial_balance', 'Balance de comprobación', 'reports.trial-balance'),
                $report('reports.income_statement', 'Estado de resultados', 'reports.income-statement'),
                $report('reports.balance_sheet', 'Balance general', 'reports.balance-sheet'),
                $report('reports.period_comparison', 'Comparativo entre periodos', 'reports.period-comparison'),
                $report('reports.multi_company_comparison', 'Comparativo de empresas', 'reports.multi-company-comparison'),
                $report('reports.document_type_register', 'Registro por tipo de documento', 'reports.document-type-register'),
                // Guardar una combinación de parámetros es escribir: no es solo lectura.
                $r('reports.saved_reports', 'Reportes guardados', 'Reportes', 'reports', ['saved-reports.*']),
                $report('reports.catalog_export', 'Exportar catálogos', 'reports.catalog-export'),
                $r('accounting.chart_of_accounts', 'Catálogo de cuentas', 'Catálogos', 'accounting', ['chart-of-accounts.*', 'account-reconciliation.*']),
                $r('accounting.document_types', 'Tipos de documento', 'Catálogos', 'accounting', ['document-types.*', 'document-type-number-series.*']),
            ]],
            ['key' => 'cost_fx', 'label' => 'Centros de costo y cambiario', 'screens' => [
                $r('accounting.exchange_rates', 'Tipos de cambio', 'Operación', 'accounting', ['exchange-rates.*']),
                $r('accounting.fx_revaluation_run', 'Diferencial: ejecutar', 'Operación', 'accounting', ['fx-revaluation.create', 'fx-revaluation.preview', 'fx-revaluation.store']),
                $r('accounting.fx_revaluation_history', 'Diferencial: historial', 'Operación', 'accounting', ['fx-revaluation.index', 'fx-revaluation.show'], true),
                $report('reports.cost_center', 'Auxiliar por centro de costo', 'reports.cost-center'),
                $report('reports.cost_allocation_rule', 'Reporte de normas de reparto', 'reports.cost-allocation-rule'),
                $r('accounting.cost_centers', 'Centros de costo', 'Catálogos', 'accounting', ['cost-centers.*']),
                $r('accounting.cost_allocation_rules', 'Normas de reparto', 'Catálogos', 'accounting', ['cost-allocation-rules.*']),
            ]],
            ['key' => 'inventory', 'label' => 'Inventario', 'screens' => [
                $r('inventory.movements', 'Movimientos', 'Existencias', 'inventory', ['inventory-movements.*']),
                $r('inventory.transfers', 'Traslados', 'Existencias', 'inventory', ['stock-transfers.*']),
                $r('inventory.stock_counts', 'Tomas físicas', 'Existencias', 'inventory', ['stock-counts.*']),
                $r('inventory.write_downs', 'Deterioro (NIC 2)', 'Existencias', 'inventory', ['inventory-write-downs.*']),
                $r('inventory.purchase_orders', 'Órdenes de compra', 'Compras', 'inventory', ['purchase-orders.*']),
                $r('inventory.reorder', 'Sugerencia de compra', 'Compras', 'inventory', ['reorder.*']),
                $r('inventory.supplier_invoices', 'Facturas de proveedor', 'Compras', 'inventory', ['supplier-invoices.*', 'supplier-credit-notes.*']),
                $r('inventory.landed_costs', 'Costos de importación', 'Compras', 'inventory', ['landed-costs.*']),
                $r('inventory.import_costs', 'Rubros de nacionalización', 'Compras', 'inventory', ['import-costs.*']),
                $r('inventory.production_orders', 'Órdenes de fabricación', 'Producción', 'inventory', ['production-orders.*']),
                $r('inventory.bills_of_materials', 'Listas de materiales', 'Producción', 'inventory', ['bills-of-materials.*']),
                $report('inventory.reports', 'Reportes de inventario', 'inventory-reports', 'inventory'),
                $report('reports.inventory_valuation', 'Existencias valorizadas', 'reports.inventory-valuation'),
                $report('reports.inventory_aging', 'Antigüedad de inventario', 'reports.inventory-aging'),
                $report('inventory.lot_expiry', 'Lotes por vencer', 'lot-expiry', 'inventory'),
                $r('inventory.items', 'Artículos', 'Catálogos', 'inventory', ['items.*', 'item-serials.*', 'item-lots.*']),
                $r('inventory.item_groups', 'Grupos de artículos', 'Catálogos', 'inventory', ['item-groups.*']),
                $r('inventory.price_lists', 'Listas de precios', 'Catálogos', 'inventory', ['price-lists.*']),
                $r('inventory.warehouses', 'Almacenes', 'Catálogos', 'inventory', ['warehouses.*', 'warehouse-bins.*']),
                $r('inventory.units', 'Unidades de medida', 'Catálogos', 'inventory', ['units-of-measure.*']),
                $r('inventory.gl_determinations', 'Determinación de cuentas', 'Configuración', 'inventory', ['gl-determinations.*']),
            ]],
            ['key' => 'billing', 'label' => 'Facturación', 'screens' => [
                $r('billing.sales_orders', 'Órdenes de pedido', 'Operación', 'billing', ['sales-orders.*']),
                // Consultar lo emitido; emitir es «Nueva factura».
                $r('billing.sales_documents', 'Comprobantes', 'Operación', 'billing', ['sales-documents.*'], true),
                $r('billing.new_invoice', 'Nueva factura', 'Operación', 'billing', ['sales-documents.create', 'sales-documents.store']),
                $report('billing.price_overrides', 'Cambios de precio autorizados', 'price-overrides', 'billing'),
                $r('billing.settings', 'Parámetros de facturación', 'Configuración', 'billing', ['billing-settings.*']),
            ]],
            ['key' => 'payroll', 'label' => 'Planillas', 'screens' => [
                $r('payroll.employees', 'Empleados', 'Personal', 'payroll', ['employees.*', 'employee-notes.*']),
                $r('payroll.personnel_actions', 'Acciones de personal', 'Personal', 'payroll', ['personnel-actions.*']),
                $r('payroll.vacations', 'Vacaciones', 'Personal', 'payroll', ['vacations.*']),
                $r('payroll.labor_settlements', 'Liquidaciones laborales', 'Personal', 'payroll', ['labor-settlements.*']),
                $r('payroll.periods', 'Períodos de planilla', 'Planilla', 'payroll', ['payroll-periods.*', 'payslips.*']),
                $r('payroll.recurring_inputs', 'Rubros fijos', 'Planilla', 'payroll', ['recurring-inputs.*']),
                $r('payroll.deductions', 'Deducciones y préstamos', 'Planilla', 'payroll', ['employee-deductions.*']),
                $report('payroll.reports', 'Reportes de planilla', 'payroll-reports', 'payroll'),
                $r('payroll.job_structure', 'Departamentos y puestos', 'Configuración', 'payroll', ['job-structure.*', 'departments.*', 'job-positions.*']),
                $r('payroll.settings', 'Parámetros de planilla', 'Configuración', 'payroll', ['payroll-settings.*']),
            ]],
            ['key' => 'business_partners', 'label' => 'Socios de negocio', 'screens' => [
                $r('business_partners.partners', 'Socios de negocio', 'Operación', 'business_partners', ['business-partners.*']),
                $report('reports.aging', 'Antigüedad de saldos', 'reports.aging'),
                $r('business_partners.categories', 'Categorías de socios', 'Catálogos', 'business_partners', ['bp-categories.*']),
            ]],
            ['key' => 'banking', 'label' => 'Bancos', 'screens' => [
                $r('banking.reconciliations', 'Conciliaciones bancarias', 'Operación', 'banking', ['bank-reconciliations.*']),
                $report('banking.reconciliation_report', 'Reporte de conciliaciones', 'bank-reconciliation-report', 'banking'),
                $report('reports.cash_flow_projection', 'Proyección de cobros y pagos', 'reports.cash-flow-projection'),
                $r('banking.accounts', 'Cuentas bancarias', 'Catálogos', 'banking', ['bank-accounts.*']),
            ]],
            ['key' => 'tax', 'label' => 'Impuestos', 'screens' => [
                $report('tax.report', 'Reporte de IVA', 'tax-report', 'tax'),
                // El catálogo se consulta sin permiso (lo usan otras pantallas,
                // ver routes/web.php); este permiso cuida el menú y la edición
                // de los indicadores propios.
                $r('tax.tax_rates', 'Indicadores de impuesto', 'Catálogos', 'tax', ['tax-rates.*']),
            ]],
        ];
    }

    /**
     * Todas las pantallas, por clave, con su sección.
     *
     * @return array<string, array{key: string, label: string, group: string, module: string, routes: list<string>, read_only: bool, section: string}>
     */
    public static function screens(): array
    {
        if (self::$screens !== null) {
            return self::$screens;
        }

        $screens = [];
        foreach (self::sections() as $section) {
            foreach ($section['screens'] as $screen) {
                $screens[$screen['key']] = $screen + ['section' => $section['key']];
            }
        }

        return self::$screens = $screens;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::screens());
    }

    public static function exists(string $key): bool
    {
        return isset(self::screens()[$key]);
    }

    /** @return array{key: string, label: string, group: string, module: string, routes: list<string>, read_only: bool, section: string}|null */
    public static function find(string $key): ?array
    {
        return self::screens()[$key] ?? null;
    }

    /** El nivel más alto que admite la pantalla: Lectura en los reportes. */
    public static function maxLevel(string $key): string
    {
        return (self::find($key)['read_only'] ?? false) ? 'read' : 'read_write';
    }

    /**
     * La pantalla de una ruta, o null si no es de ninguna (el Panel, Mi
     * cuenta, Administración, o una ruta compartida de SHARED_ROUTES).
     */
    public static function screenForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        if (array_key_exists($routeName, self::$routeCache)) {
            return self::$routeCache[$routeName];
        }

        return self::$routeCache[$routeName] = self::resolve($routeName);
    }

    /** ¿La ruta es de las compartidas? */
    public static function isShared(string $routeName): bool
    {
        foreach (self::SHARED_ROUTES as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    private static function resolve(string $routeName): ?string
    {
        // Un nombre exacto gana a cualquier comodín, sea de una pantalla o de
        // las compartidas.
        if (in_array($routeName, self::SHARED_ROUTES, true)) {
            return null;
        }

        foreach (self::screens() as $key => $screen) {
            if (in_array($routeName, $screen['routes'], true)) {
                return $key;
            }
        }

        if (self::isShared($routeName)) {
            return null;
        }

        // Entre comodines, el más largo (el más específico).
        $best = null;
        $bestLength = -1;

        foreach (self::screens() as $key => $screen) {
            foreach ($screen['routes'] as $pattern) {
                if (str_ends_with($pattern, '*') && Str::is($pattern, $routeName) && strlen($pattern) > $bestLength) {
                    $best = $key;
                    $bestLength = strlen($pattern);
                }
            }
        }

        return $best;
    }
}
