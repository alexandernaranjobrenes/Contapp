<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Clasificación ABC: qué pocos artículos concentran casi todo el valor.
 *
 * ── Por qué vale la pena ─────────────────────────────────────────────────
 *
 * En casi cualquier inventario, alrededor del 20% de los artículos explica
 * el 80% del dinero. Tratarlos a todos igual es el error caro: se gasta el
 * mismo esfuerzo de conteo, negociación y control en un artículo que mueve
 * millones y en uno que mueve miles. Este reporte dice cuáles son cuáles.
 *
 *   Clase A — hasta el 80% acumulado: contarlos seguido, negociarlos, no
 *             quebrarse nunca
 *   Clase B — del 80% al 95%: control normal
 *   Clase C — el resto: comprar por lote grande y no perderles el tiempo
 *
 * ── Dos criterios, y no dan lo mismo ─────────────────────────────────────
 *
 * Por **consumo** (lo que salió en el período) prioriza lo que mueve el
 * negocio: sirve para decidir dónde poner el esfuerzo comercial y de
 * compras. Por **valor en existencia** prioriza dónde está la plata parada
 * hoy: sirve para decidir qué contar y qué liquidar. Un artículo caro que
 * no se vende es clase C por consumo y clase A por existencia — y esa
 * contradicción es exactamente el hallazgo que uno busca.
 */
class AbcAnalysisReport implements InventoryReport
{
    use Concerns\FiltersDateRange;

    private const CLASS_A_THRESHOLD = 80.0;

    private const CLASS_B_THRESHOLD = 95.0;

    public function code(): string
    {
        return 'abc-analysis';
    }

    public function label(): string
    {
        return 'Análisis ABC';
    }

    public function description(): string
    {
        return 'Ordena los artículos por su peso en el total y los clasifica en A, B y C según el acumulado.';
    }

    public function decision(): string
    {
        return 'Dónde concentrar el control: qué pocos artículos explican la mayor parte del dinero y cuáles no merecen el esfuerzo.';
    }

    public function group(): string
    {
        return 'Indicadores';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('criterion', 'Criterio', ReportFilter::SELECT, default: 'consumption', options: [
                'consumption' => 'Consumo del período (dónde está el movimiento)',
                'stock_value' => 'Valor en existencia (dónde está la plata parada)',
            ], hint: 'Los dos criterios dan listas distintas, y la diferencia entre ambas es el hallazgo.'),
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_year'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('warehouse_id', 'Almacén', ReportFilter::SELECT, optionSource: 'warehouses'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('only_class', 'Mostrar solo la clase', ReportFilter::SELECT, options: [
                'A' => 'Clase A', 'B' => 'Clase B', 'C' => 'Clase C',
            ]),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $byConsumption = ($filters['criterion'] ?? 'consumption') === 'consumption';

        $values = $byConsumption
            ? $this->consumptionValues($company, $filters)
            : $this->stockValues($company, $filters);

        $items = DB::table('items')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->where('items.company_id', $company->id)
            ->where('items.is_inventory_item', true)
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->get(['items.id', 'items.code', 'items.name', 'item_groups.name as group_name'])
            ->keyBy('id');

        // Solo lo que tiene valor: un artículo en cero no tiene lugar en un
        // Pareto y desplazaría el acumulado de todo lo que sí importa.
        $ranked = collect($values)
            ->filter(fn (array $v) => $v['value'] > 0 && $items->has($v['item_id']))
            ->sortByDesc('value')
            ->values();

        $total = $ranked->sum('value');
        $accumulated = 0.0;
        $rows = [];
        $position = 0;

        foreach ($ranked as $entry) {
            $item = $items[$entry['item_id']];
            $share = $total > 0 ? ($entry['value'] / $total) * 100 : 0;
            // La clase se decide con el acumulado ANTES de esta fila: el
            // artículo que CRUZA el 80% todavía es clase A, porque hasta él
            // el 80% no se había alcanzado. Decidirlo con el acumulado
            // posterior clasificaría como C al único artículo de un catálogo
            // de uno —se lleva el 100%— y eso no es un Pareto, es un error.
            $accumulatedBefore = $accumulated;
            $accumulated += $share;
            $position++;

            $rows[] = [
                'position' => $position,
                'code' => $item->code,
                'name' => $item->name,
                'group_name' => $item->group_name,
                'quantity' => $entry['quantity'],
                'value' => $entry['value'],
                'share' => $share,
                'accumulated' => $accumulated,
                'abc_class' => match (true) {
                    $accumulatedBefore < self::CLASS_A_THRESHOLD => 'A',
                    $accumulatedBefore < self::CLASS_B_THRESHOLD => 'B',
                    default => 'C',
                },
            ];
        }

        if (filled($filters['only_class'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['abc_class'] === $filters['only_class']));
        }

        $counts = array_count_values(array_column($rows, 'abc_class'));

        return new ReportResult(
            columns: [
                new ReportColumn('position', '#', ReportColumn::NUMBER),
                new ReportColumn('abc_class', 'Clase'),
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 30),
                new ReportColumn('group_name', 'Grupo'),
                new ReportColumn('quantity', 'Cantidad', ReportColumn::NUMBER),
                new ReportColumn('value', $byConsumption ? 'Costo consumido' : 'Valor en existencia', ReportColumn::MONEY, totalizable: true),
                new ReportColumn('share', '% del total', ReportColumn::PERCENT),
                new ReportColumn('accumulated', '% acumulado', ReportColumn::PERCENT),
            ],
            rows: $rows,
            notes: [
                $byConsumption
                    ? "Criterio: costo de lo consumido entre {$filters['from']} y {$filters['to']}."
                    : 'Criterio: valor de la existencia actual, al costo promedio.',
                'Clase A hasta el 80% acumulado, B hasta el 95%, C el resto.',
                sprintf(
                    'Resultado: %d artículo(s) clase A, %d clase B y %d clase C.',
                    $counts['A'] ?? 0, $counts['B'] ?? 0, $counts['C'] ?? 0
                ),
                'Probá el reporte con los dos criterios: un artículo que sale clase C por consumo y clase A por existencia es plata parada que no se está vendiendo.',
            ],
        );
    }

    /**
     * @return array<int, array{item_id: int, quantity: float, value: float}>
     */
    private function consumptionValues(Company $company, array $filters): array
    {
        $query = DB::table('stock_journals')
            ->join('inventory_document_lines', 'inventory_document_lines.id', '=', 'stock_journals.inventory_document_line_id')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('stock_journals.company_id', $company->id)
            ->where('stock_journals.direction', 'out')
            ->whereIn('inventory_documents.operation', ['sales_issue', 'production_issue', 'goods_issue']);

        return $this->inDateRange($query, 'stock_journals.posting_date', $filters['from'], $filters['to'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('stock_journals.warehouse_id', $v))
            ->groupBy('stock_journals.item_id')
            ->get([
                'stock_journals.item_id',
                DB::raw('SUM(stock_journals.quantity) as quantity'),
                DB::raw('SUM(stock_journals.total_cost_local) as value'),
            ])
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'quantity' => (float) $row->quantity,
                'value' => (float) $row->value,
            ])
            ->all();
    }

    /**
     * @return array<int, array{item_id: int, quantity: float, value: float}>
     */
    private function stockValues(Company $company, array $filters): array
    {
        return DB::table('item_warehouses')
            ->join('items', 'items.id', '=', 'item_warehouses.item_id')
            ->where('items.company_id', $company->id)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('item_warehouses.warehouse_id', $v))
            ->groupBy('item_warehouses.item_id', 'items.avg_cost_local')
            ->get([
                'item_warehouses.item_id',
                'items.avg_cost_local',
                DB::raw('SUM(item_warehouses.on_hand) as quantity'),
            ])
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'quantity' => (float) $row->quantity,
                'value' => (float) $row->quantity * (float) $row->avg_cost_local,
            ])
            ->all();
    }
}
