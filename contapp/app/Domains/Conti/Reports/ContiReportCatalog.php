<?php

namespace App\Domains\Conti\Reports;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Services\BalanceSheetService;
use App\Domains\Accounting\Services\CostAllocationRuleReportService;
use App\Domains\Accounting\Services\CostCenterReportService;
use App\Domains\Accounting\Services\IncomeStatementService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\PeriodComparisonService;
use App\Domains\Accounting\Services\TrialBalanceService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\AgingService;
use App\Domains\BusinessPartners\Services\CashFlowProjectionService;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiRedactor;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Reports\InventoryReportRegistry;
use App\Domains\Inventory\Services\InventoryAgingService;
use App\Domains\Inventory\Services\InventoryValuationService;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\JobPosition;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Reports\PayrollReportRegistry;
use App\Domains\Reporting\Reports\HasSelectableColumns;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\TabularReport;
use App\Domains\Tax\Services\TaxReportService;
use Illuminate\Support\Facades\Validator;

/**
 * Los reportes que Conti puede pedir (GET /api/conti/reportes/{clave}).
 *
 * Cada uno corre el mismo servicio que su pantalla, con los mismos valores
 * por defecto, y pide la misma pantalla del menú. Los reportes tabulares de
 * Inventario y de Planillas entran solos desde sus registros: uno nuevo ahí
 * aparece acá sin tocar nada.
 */
final class ContiReportCatalog
{
    /** Tope de filas de un reporte tabular: lo demás se pide con filtros. */
    public const MAX_ROWS = 300;

    /** @return array<string, ContiReport> */
    public static function all(): array
    {
        $reports = [];

        foreach ([...self::fixed(), ...self::tabular()] as $report) {
            $reports[$report->key] = $report;
        }

        return $reports;
    }

    public static function find(string $key): ?ContiReport
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<ContiReport> */
    public static function availableTo(ContiContext $context): array
    {
        return array_values(array_filter(self::all(), fn (ContiReport $r) => $context->allowsAny($r->screens)));
    }

    /** @return list<ContiReport> */
    private static function fixed(): array
    {
        $period = ['desde' => 'AAAA-MM-DD', 'hasta' => 'AAAA-MM-DD'];

        return [
            new ContiReport(
                'balance-comprobacion', 'Balance de comprobación',
                'Saldo inicial, débitos, créditos y saldo final de cada cuenta en un rango de fechas.',
                ['reports.trial_balance'],
                $period + ['ocultar_sin_movimiento' => 'sí | no (por defecto sí)'],
                fn (array $p) => app(TrialBalanceService::class)->build(self::company(), self::date($p, 'desde'), self::date($p, 'hasta'), self::bool($p, 'ocultar_sin_movimiento', true)),
            ),
            new ContiReport(
                'estado-resultados', 'Estado de resultados',
                'Ingresos, costos y gastos de un período, con la utilidad. Por defecto, el mes actual.',
                ['reports.income_statement'],
                $period + ['ocultar_sin_movimiento' => 'sí | no (por defecto sí)'],
                fn (array $p) => app(IncomeStatementService::class)->build(
                    self::company(),
                    self::date($p, 'desde') ?? now()->startOfMonth()->format('Y-m-d'),
                    self::date($p, 'hasta') ?? now()->endOfMonth()->format('Y-m-d'),
                    self::bool($p, 'ocultar_sin_movimiento', true),
                ),
            ),
            new ContiReport(
                'balance-general', 'Balance general',
                'Activo, pasivo y patrimonio a una fecha. Por defecto, hoy.',
                ['reports.balance_sheet'],
                ['al' => 'AAAA-MM-DD'],
                fn (array $p) => app(BalanceSheetService::class)->build(self::company(), self::date($p, 'al') ?? now()->format('Y-m-d')),
            ),
            new ContiReport(
                'comparativo-periodos', 'Comparativo entre períodos',
                'Dos períodos lado a lado: balance al cierre de cada uno y resultados de cada uno, con la variación.',
                ['reports.period_comparison'],
                ['desde_1' => 'AAAA-MM-DD', 'hasta_1' => 'AAAA-MM-DD', 'desde_2' => 'AAAA-MM-DD', 'hasta_2' => 'AAAA-MM-DD'],
                fn (array $p) => app(PeriodComparisonService::class)->build(
                    self::company(),
                    self::date($p, 'desde_1') ?? now()->startOfYear()->format('Y-m-d'),
                    self::date($p, 'hasta_1') ?? now()->startOfYear()->endOfMonth()->format('Y-m-d'),
                    self::date($p, 'desde_2') ?? now()->startOfMonth()->format('Y-m-d'),
                    self::date($p, 'hasta_2') ?? now()->endOfMonth()->format('Y-m-d'),
                ),
            ),
            new ContiReport(
                'mayor', 'Mayor auxiliar (saldo y movimientos)',
                'Saldo inicial, movimientos con saldo acumulado y saldo final de una cuenta, un socio o un centro de costo. Solo lo contabilizado.',
                ['accounting.chart_of_accounts', 'accounting.journal_entries', 'reports.trial_balance', 'business_partners.partners', 'accounting.cost_centers', 'reports.cost_center'],
                ['tipo' => 'cuenta | socio | centro_costo', 'codigo' => 'código de la cuenta, el socio o el centro de costo'] + $period,
                fn (array $p) => self::ledger($p),
            ),
            new ContiReport(
                'antiguedad-saldos', 'Antigüedad de saldos',
                'Cuentas por cobrar y por pagar por tramos de vencimiento a una fecha.',
                ['reports.aging'],
                ['al' => 'AAAA-MM-DD (por defecto hoy)', 'socios' => 'ambos | clientes | proveedores', 'cortes' => 'días separados por coma, ej. 30,60,90'],
                fn (array $p) => app(AgingService::class)->build(
                    self::company(),
                    self::date($p, 'al') ?? now()->format('Y-m-d'),
                    match (mb_strtolower((string) ($p['socios'] ?? 'ambos'))) {
                        'clientes', 'cliente', 'client' => 'client',
                        'proveedores', 'proveedor', 'supplier' => 'supplier',
                        default => 'both',
                    },
                    self::buckets($p),
                ),
            ),
            new ContiReport(
                'proyeccion-cobros-pagos', 'Proyección de cobros y pagos',
                'Lo que se va a cobrar y pagar según los vencimientos de las partidas abiertas.',
                ['reports.cash_flow_projection'],
                ['al' => 'AAAA-MM-DD (por defecto hoy)', 'cortes' => 'días separados por coma, ej. 15,30,60,90'],
                fn (array $p) => app(CashFlowProjectionService::class)->build(self::company(), self::date($p, 'al') ?? now()->format('Y-m-d'), self::buckets($p)),
            ),
            new ContiReport(
                'reporte-iva', 'Reporte de IVA',
                'Base gravable e impuesto por clasificación, según la fecha de contabilización. Por defecto, el mes actual.',
                ['tax.report'],
                $period,
                fn (array $p) => app(TaxReportService::class)->generate(
                    self::company(),
                    new \DateTime(self::date($p, 'desde') ?? now()->startOfMonth()->format('Y-m-d')),
                    new \DateTime(self::date($p, 'hasta') ?? now()->endOfMonth()->format('Y-m-d')),
                ),
            ),
            new ContiReport(
                'auxiliar-centro-costo', 'Auxiliar por centro de costo',
                'Débitos y créditos por cuenta dentro de cada centro de costo, en un rango de fechas.',
                ['reports.cost_center'],
                $period,
                fn (array $p) => app(CostCenterReportService::class)->build(self::company(), self::date($p, 'desde'), self::date($p, 'hasta')),
            ),
            new ContiReport(
                'normas-reparto-real', 'Reporte de normas de reparto',
                'Compara el porcentaje definido en cada norma con el que resultó en los asientos contabilizados.',
                ['reports.cost_allocation_rule'],
                $period,
                fn (array $p) => app(CostAllocationRuleReportService::class)->build(self::company(), self::date($p, 'desde'), self::date($p, 'hasta')),
            ),
            new ContiReport(
                'existencias-valorizadas', 'Existencias valorizadas',
                'Cantidad, costo y valor del inventario a una fecha, reconstruido desde el kardex.',
                ['reports.inventory_valuation'],
                ['al' => 'AAAA-MM-DD (por defecto hoy)', 'almacen' => 'código del almacén', 'grupo' => 'código del grupo', 'ocultar_en_cero' => 'sí | no (por defecto sí)'],
                fn (array $p) => app(InventoryValuationService::class)->build(
                    self::company(),
                    self::date($p, 'al') ?? now()->format('Y-m-d'),
                    self::idByCode(Warehouse::class, $p['almacen'] ?? null, 'almacén'),
                    self::idByCode(ItemGroup::class, $p['grupo'] ?? null, 'grupo'),
                    self::bool($p, 'ocultar_en_cero', true),
                ),
            ),
            new ContiReport(
                'antiguedad-inventario', 'Antigüedad de inventario',
                'Cuánto tiempo lleva el inventario sin moverse, por tramos de días.',
                ['reports.inventory_aging'],
                ['al' => 'AAAA-MM-DD (por defecto hoy)', 'cortes' => 'días separados por coma', 'almacen' => 'código del almacén', 'grupo' => 'código del grupo'],
                fn (array $p) => app(InventoryAgingService::class)->build(
                    self::company(),
                    self::date($p, 'al') ?? now()->format('Y-m-d'),
                    self::buckets($p),
                    self::idByCode(Warehouse::class, $p['almacen'] ?? null, 'almacén'),
                    self::idByCode(ItemGroup::class, $p['grupo'] ?? null, 'grupo'),
                ),
            ),
            new ContiReport(
                'lotes-por-vencer', 'Lotes por vencer',
                'Lotes con existencia que vencen dentro de los próximos días.',
                ['inventory.lot_expiry'],
                ['dias' => 'horizonte en días (por defecto 90)', 'almacen' => 'código del almacén'],
                fn (array $p) => self::lotExpiry($p),
            ),
        ];
    }

    /**
     * Los reportes tabulares de Inventario y de Planillas, desde sus
     * registros.
     *
     * @return list<ContiReport>
     */
    private static function tabular(): array
    {
        $reports = [];

        foreach (app(InventoryReportRegistry::class)->all() as $definition) {
            $reports[] = self::fromTabular('inventario-'.$definition->code(), $definition, ['inventory.reports']);
        }

        foreach (app(PayrollReportRegistry::class)->all() as $definition) {
            $reports[] = self::fromTabular('planilla-'.$definition->code(), $definition, ['payroll.reports']);
        }

        return $reports;
    }

    private static function fromTabular(string $key, TabularReport $definition, array $screens): ContiReport
    {
        $params = [];

        foreach ($definition->filters() as $filter) {
            $params[$filter->key] = self::describeFilter($filter);
        }

        if ($definition instanceof HasSelectableColumns) {
            $params['columnas'] = 'claves de columna separadas por coma (por defecto, las de la pantalla): '
                .implode(', ', array_map(fn (ReportColumn $c) => $c->key, array_filter(
                    $definition->availableColumns(),
                    fn (ReportColumn $c) => ! ContiRedactor::isRemovedKey($c->key),
                )));
        }

        return new ContiReport(
            $key,
            $definition->label(),
            $definition->description().' Sirve para: '.$definition->decision(),
            $screens,
            $params,
            fn (array $p) => self::runTabular($definition, $p),
        );
    }

    private static function describeFilter(ReportFilter $filter): string
    {
        $what = match ($filter->type) {
            ReportFilter::DATE => 'AAAA-MM-DD',
            ReportFilter::BOOLEAN => 'sí | no',
            ReportFilter::SELECT => $filter->options !== null
                ? ContiFilters::options(array_map(fn ($o) => is_array($o) ? ($o['label'] ?? json_encode($o)) : $o, $filter->options))
                : ($filter->optionSource === 'payroll_periods' ? 'id o nombre del período' : 'código'),
            default => 'texto',
        };

        return $filter->label.': '.$what.($filter->default !== null && ! is_bool($filter->default) ? " (por defecto {$filter->default})" : '');
    }

    private static function runTabular(TabularReport $definition, array $params): array
    {
        $filters = [];

        foreach ($definition->filters() as $filter) {
            $value = $params[$filter->key] ?? null;

            if ($value === null || $value === '') {
                $filters[$filter->key] = match ($filter->default) {
                    'today' => now()->format('Y-m-d'),
                    'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
                    'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
                    'last_calculated_period' => null,
                    default => $filter->default,
                };

                continue;
            }

            $filters[$filter->key] = match ($filter->type) {
                ReportFilter::DATE => self::date([$filter->key => $value], $filter->key),
                ReportFilter::BOOLEAN => ContiFilters::truthy((string) $value),
                ReportFilter::SELECT => $filter->options !== null
                    ? ContiFilters::keyFor((string) $value, array_map(fn ($o) => is_array($o) ? ($o['label'] ?? '') : $o, $filter->options))
                    : self::optionId($filter->optionSource, (string) $value, $filter->label),
                default => mb_substr((string) $value, 0, 100),
            };
        }

        $result = $definition->build(self::company(), $filters);

        $columns = $result->columns;

        if ($definition instanceof HasSelectableColumns) {
            $available = array_map(fn (ReportColumn $c) => $c->key, $definition->availableColumns());
            $requested = array_values(array_intersect(array_map('trim', explode(',', (string) ($params['columnas'] ?? ''))), $available));
            $keys = $requested !== [] ? $requested : $definition->defaultColumns();
            $columns = array_values(array_filter($columns, fn (ReportColumn $c) => in_array($c->key, $keys, true)));
        }

        $columns = array_values(array_filter($columns, fn (ReportColumn $c) => ! ContiRedactor::isRemovedKey($c->key)));
        $keys = array_map(fn (ReportColumn $c) => $c->key, $columns);

        $rows = array_map(
            fn (array $row) => array_intersect_key($row, array_flip($keys)),
            array_slice($result->rows, 0, self::MAX_ROWS),
        );

        return [
            'columnas' => array_map(fn (ReportColumn $c) => ['clave' => $c->key, 'nombre' => $c->label, 'formato' => $c->format], $columns),
            'filas' => $rows,
            'total_filas' => count($result->rows),
            'recortado' => count($result->rows) > self::MAX_ROWS
                ? 'Se muestran las primeras '.self::MAX_ROWS.' filas: usá los filtros para acotar.'
                : null,
            'totales' => array_intersect_key($result->computedTotals(), array_flip($keys)),
            'notas' => $result->notes,
            'filtros_aplicados' => $filters,
        ];
    }

    private static function ledger(array $p): mixed
    {
        $context = app(ContiContext::class);
        $code = trim((string) ($p['codigo'] ?? ''));

        if ($code === '') {
            abort(422, 'Indicá el «codigo» de la cuenta, el socio o el centro de costo.');
        }

        [$dimension, $model, $screens, $what] = match (mb_strtolower((string) ($p['tipo'] ?? 'cuenta'))) {
            'socio' => ['business-partner', BusinessPartner::class, ['business_partners.partners'], 'socio'],
            'centro', 'centro_costo', 'centro-costo' => ['cost-center', CostCenter::class, ['accounting.cost_centers', 'reports.cost_center'], 'centro de costo'],
            default => ['account', ChartOfAccount::class, ['accounting.chart_of_accounts', 'accounting.journal_entries', 'reports.trial_balance'], 'cuenta'],
        };

        $context->authorize($screens);

        $id = $model::where('code', $code)->value('id') ?? abort(404, "No existe el {$what} con código «{$code}» en esta compañía.");

        return app(LedgerService::class)->build(self::company(), $dimension, $id, self::date($p, 'desde'), self::date($p, 'hasta'));
    }

    private static function lotExpiry(array $p): array
    {
        $days = (int) ($p['dias'] ?? 90);

        if ($days < 1 || $days > 3650) {
            abort(422, 'El horizonte («dias») va de 1 a 3650.');
        }

        $warehouseId = self::idByCode(Warehouse::class, $p['almacen'] ?? null, 'almacén');
        $today = now()->startOfDay();

        return ItemLot::query()
            ->whereHas('stockLevels', fn ($q) => $q->where('on_hand', '>', 0)->when($warehouseId !== null, fn ($qq) => $qq->where('warehouse_id', $warehouseId)))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays($days)->format('Y-m-d'))
            ->whereHas('item')
            ->with(['item:id,code,name', 'stockLevels' => fn ($q) => $q->where('on_hand', '>', 0)
                ->when($warehouseId !== null, fn ($qq) => $qq->where('warehouse_id', $warehouseId))
                ->with('warehouse:id,code')])
            ->orderBy('expires_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (ItemLot $lot) => [
                'articulo' => trim($lot->item?->code.' '.$lot->item?->name),
                'lote' => $lot->code,
                'vence' => $lot->expires_at->format('Y-m-d'),
                'dias_para_vencer' => (int) $today->diffInDays($lot->expires_at, false),
                'existencia' => (float) $lot->stockLevels->sum('on_hand'),
                'almacenes' => $lot->stockLevels->map(fn ($s) => $s->warehouse?->code)->filter()->unique()->values()->all(),
                'estado' => $lot->status,
            ])
            ->all();
    }

    private static function company(): Company
    {
        return app(ContiContext::class)->company();
    }

    private static function date(array $p, string $key): ?string
    {
        $value = $p[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        Validator::make([$key => $value], [$key => ['date']], [], [$key => $key])->validate();

        return date('Y-m-d', strtotime((string) $value));
    }

    private static function bool(array $p, string $key, bool $default): bool
    {
        return array_key_exists($key, $p) && $p[$key] !== '' ? ContiFilters::truthy((string) $p[$key]) : $default;
    }

    private static function buckets(array $p): ?string
    {
        $value = trim((string) ($p['cortes'] ?? ''));

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^\s*\d+\s*(,\s*\d+\s*)*$/', $value)) {
            abort(422, 'Los «cortes» son días separados por coma, ej. 30,60,90.');
        }

        return $value;
    }

    /** @param  class-string  $model */
    private static function idByCode(string $model, mixed $code, string $what): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $model::where('code', (string) $code)->value('id')
            ?? abort(404, "No existe el {$what} con código «{$code}» en esta compañía.");
    }

    /** Un filtro de lista de un reporte tabular: llega el código, va el id. */
    private static function optionId(?string $source, string $value, string $label): ?int
    {
        if (ctype_digit($value) && $source === 'payroll_periods') {
            return (int) $value;
        }

        $id = match ($source) {
            'warehouses' => Warehouse::where('code', $value)->value('id'),
            'item_groups' => ItemGroup::where('code', $value)->value('id'),
            'items' => Item::where('code', $value)->value('id'),
            'price_lists' => PriceList::where('code', $value)->value('id'),
            'cost_centers' => CostCenter::where('code', $value)->value('id'),
            'departments' => Department::where(fn ($q) => $q->where('code', $value)->orWhere('name', $value))->value('id'),
            'job_positions' => JobPosition::where(fn ($q) => $q->where('code', $value)->orWhere('name', $value))->value('id'),
            'employees' => Employee::where('code', $value)->value('id'),
            'payroll_periods' => PayrollPeriod::where('name', $value)->value('id'),
            default => ctype_digit($value) ? (int) $value : null,
        };

        return $id ?? abort(404, "No encontré «{$value}» para el filtro «{$label}».");
    }
}
