<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Rotación, días de inventario y cobertura por artículo.
 *
 * ── Las tres fórmulas, y qué contesta cada una ───────────────────────────
 *
 *     rotación    = costo de lo consumido en el período / inventario promedio
 *     días inv.   = días del período / rotación
 *     cobertura   = existencia actual / consumo diario promedio
 *
 * La **rotación** dice cuántas veces se dio vuelta el inventario: alta es
 * mercancía que se mueve, baja es plata dormida. Los **días de inventario**
 * son la misma verdad en la unidad en que la gente piensa. La **cobertura**
 * es la que dispara acciones hoy: con lo que tengo y al ritmo al que sale,
 * ¿para cuántos días me alcanza?
 *
 * ── El inventario promedio es un promedio de verdad ──────────────────────
 *
 * Se toma (existencia al inicio + existencia al final) / 2, valorizadas al
 * costo promedio actual. Usar solo la existencia final —el atajo habitual—
 * infla la rotación de cualquier artículo que se haya agotado justo antes
 * del corte, que es precisamente el que uno quiere detectar.
 *
 * El costo del período se toma del kardex, que es el único registro de lo
 * que de verdad salió y a qué costo.
 *
 * ── Qué NO mide ──────────────────────────────────────────────────────────
 *
 * Solo las salidas de consumo real (venta y producción). Un traslado entre
 * almacenes no es consumo: contarlo haría que mover mercancía de bodega
 * pareciera rotación, y bastaría con mandarla de ida y vuelta para "mejorar"
 * el indicador.
 */
class TurnoverKpiReport implements InventoryReport
{
    use Concerns\FiltersDateRange;

    /** Salidas que SÍ son consumo. El resto mueve, no consume. */
    private const CONSUMPTION_OPERATIONS = ['sales_issue', 'production_issue', 'goods_issue'];

    public function code(): string
    {
        return 'turnover-kpi';
    }

    public function label(): string
    {
        return 'Rotación y cobertura';
    }

    public function description(): string
    {
        return 'Cuántas veces rota cada artículo en el período, a cuántos días de inventario equivale y para cuántos días alcanza lo que hay.';
    }

    public function decision(): string
    {
        return 'Dónde está la plata dormida y qué artículo se va a agotar primero: los dos extremos de la lista son los que hay que atender.';
    }

    public function group(): string
    {
        return 'Indicadores';
    }

    /**
     * Columnas de identidad que se congelan al desplazar la tabla de
     * lado: código y nombre.
     */
    public function frozenColumns(): int
    {
        return 2;
    }

    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_year'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('warehouse_id', 'Almacén', ReportFilter::SELECT, optionSource: 'warehouses'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('order', 'Ordenar por', ReportFilter::SELECT, default: 'slowest', options: [
                'slowest' => 'Lo que menos rota primero (plata dormida)',
                'fastest' => 'Lo que más rota primero',
                'coverage' => 'Menos días de cobertura primero (riesgo de quiebre)',
                'value' => 'Mayor consumo primero',
            ]),
            new ReportFilter('hide_idle', 'Ocultar lo que no tuvo movimiento', ReportFilter::BOOLEAN),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $days = max(1, (int) \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1);
        $warehouseId = $filters['warehouse_id'] ?? null;

        $consumption = $this->consumptionInPeriod($company, $from, $to, $warehouseId);
        $closing = $this->quantityAt($company, $to, $warehouseId);
        // El día ANTERIOR al inicio: la existencia con la que se arrancó.
        $opening = $this->quantityAt($company, \Carbon\Carbon::parse($from)->subDay()->format('Y-m-d'), $warehouseId);

        $items = DB::table('items')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->where('items.company_id', $company->id)
            ->where('items.is_inventory_item', true)
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->get(['items.id', 'items.code', 'items.name', 'items.avg_cost_local', 'item_groups.name as group_name']);

        $rows = $items
            ->map(function ($item) use ($consumption, $opening, $closing, $days) {
                $cost = (float) $item->avg_cost_local;
                $consumedValue = (float) ($consumption[$item->id]['value'] ?? 0);
                $consumedQty = (float) ($consumption[$item->id]['quantity'] ?? 0);

                $openingQty = (float) ($opening[$item->id] ?? 0);
                $closingQty = (float) ($closing[$item->id] ?? 0);
                $averageValue = (($openingQty + $closingQty) / 2) * $cost;

                // Sin inventario promedio no hay rotación que calcular: un
                // artículo que nunca tuvo existencia no rotó infinitas
                // veces, simplemente no aplica.
                $turnover = $averageValue > 0 ? $consumedValue / $averageValue : null;
                $inventoryDays = $turnover !== null && $turnover > 0 ? $days / $turnover : null;

                $dailyConsumption = $consumedQty / $days;
                $coverage = $dailyConsumption > 0 ? $closingQty / $dailyConsumption : null;

                return [
                    'code' => $item->code,
                    'name' => $item->name,
                    'group_name' => $item->group_name,
                    'opening_quantity' => $openingQty,
                    'closing_quantity' => $closingQty,
                    'consumed_quantity' => $consumedQty,
                    'consumed_value' => $consumedValue,
                    'average_value' => $averageValue,
                    'turnover' => $turnover,
                    'inventory_days' => $inventoryDays,
                    'coverage_days' => $coverage,
                    'signal' => $this->signal($turnover, $coverage, $closingQty, $consumedQty),
                ];
            })
            ->when($filters['hide_idle'] ?? false, fn ($c) => $c->filter(
                fn (array $r) => $r['consumed_quantity'] > 0 || $r['closing_quantity'] > 0
            ));

        $rows = $this->sort($rows, $filters['order'] ?? 'slowest')->values()->all();

        $totalConsumed = array_sum(array_column($rows, 'consumed_value'));
        $totalAverage = array_sum(array_column($rows, 'average_value'));

        return new ReportResult(
            columns: [
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 28),
                new ReportColumn('group_name', 'Grupo'),
                new ReportColumn('opening_quantity', 'Existencia inicial', ReportColumn::NUMBER),
                new ReportColumn('closing_quantity', 'Existencia final', ReportColumn::NUMBER),
                new ReportColumn('consumed_quantity', 'Consumido', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('consumed_value', 'Costo consumido', ReportColumn::MONEY, totalizable: true),
                new ReportColumn('average_value', 'Inventario prom.', ReportColumn::MONEY, totalizable: true),
                new ReportColumn('turnover', 'Rotación (veces)', ReportColumn::PERCENT),
                new ReportColumn('inventory_days', 'Días de inventario', ReportColumn::NUMBER),
                new ReportColumn('coverage_days', 'Cobertura (días)', ReportColumn::NUMBER),
                new ReportColumn('signal', 'Señal', width: 18),
            ],
            rows: $rows,
            // La rotación del conjunto NO es la suma de las rotaciones: es el
            // consumo total sobre el inventario promedio total.
            totals: [
                'turnover' => $totalAverage > 0 ? $totalConsumed / $totalAverage : 0,
                'inventory_days' => $totalConsumed > 0 ? $days / ($totalConsumed / max($totalAverage, 0.000001)) : 0,
            ],
            notes: [
                "Período de {$days} día(s), del {$from} al {$to}.",
                'Inventario promedio = (existencia inicial + final) / 2, al costo promedio actual. Usar solo la final infla la rotación de lo que se agotó justo antes del corte.',
                'Solo cuenta el consumo real (venta, producción, salida de mercancía). Un traslado entre almacenes mueve pero no consume: contarlo permitiría "mejorar" el indicador mandando mercancía de ida y vuelta.',
                'La rotación del pie es la del conjunto —consumo total sobre inventario promedio total—, no la suma de la columna.',
            ],
        );
    }

    /**
     * Lo que salió como consumo en el período, por artículo.
     *
     * @return array<int, array{quantity: float, value: float}>
     */
    private function consumptionInPeriod(Company $company, string $from, string $to, ?int $warehouseId): array
    {
        $query = DB::table('stock_journals')
            ->join('inventory_document_lines', 'inventory_document_lines.id', '=', 'stock_journals.inventory_document_line_id')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('stock_journals.company_id', $company->id)
            ->where('stock_journals.direction', 'out')
            ->whereIn('inventory_documents.operation', self::CONSUMPTION_OPERATIONS);

        return $this->inDateRange($query, 'stock_journals.posting_date', $from, $to)
            ->when($warehouseId, fn ($q, $v) => $q->where('stock_journals.warehouse_id', $v))
            ->groupBy('stock_journals.item_id')
            ->get([
                'stock_journals.item_id',
                DB::raw('SUM(stock_journals.quantity) as quantity'),
                DB::raw('SUM(stock_journals.total_cost_local) as value'),
            ])
            ->mapWithKeys(fn ($row) => [
                (int) $row->item_id => ['quantity' => (float) $row->quantity, 'value' => (float) $row->value],
            ])
            ->all();
    }

    /**
     * La existencia a una fecha, derivada del kardex — entradas menos
     * salidas hasta ese día. No se lee de item_warehouses porque esa columna
     * dice el saldo de HOY, y acá hace falta el de una fecha pasada.
     *
     * @return array<int, float>
     */
    private function quantityAt(Company $company, string $date, ?int $warehouseId): array
    {
        $query = DB::table('stock_journals')->where('company_id', $company->id);

        return $this->upToDate($query, 'posting_date', $date)
            ->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->groupBy('item_id')
            ->get([
                'item_id',
                DB::raw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as quantity"),
            ])
            ->mapWithKeys(fn ($row) => [(int) $row->item_id => (float) $row->quantity])
            ->all();
    }

    private function sort($rows, string $order)
    {
        return match ($order) {
            // Los nulos al final en los dos sentidos: "no aplica" no es ni
            // lo peor ni lo mejor, es otra cosa.
            'fastest' => $rows->sortByDesc(fn (array $r) => $r['turnover'] ?? -1),
            'coverage' => $rows->sortBy(fn (array $r) => $r['coverage_days'] ?? PHP_INT_MAX),
            'value' => $rows->sortByDesc('consumed_value'),
            default => $rows->sortBy(fn (array $r) => $r['turnover'] ?? PHP_INT_MAX),
        };
    }

    private function signal(?float $turnover, ?float $coverage, float $onHand, float $consumed): string
    {
        if ($consumed <= 0 && $onHand > 0) {
            return 'Sin movimiento';
        }

        if ($coverage !== null && $coverage < 15) {
            return 'Se agota pronto';
        }

        if ($turnover !== null && $turnover < 1) {
            return 'Rotación baja';
        }

        return '';
    }
}
