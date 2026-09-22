<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilidad REAL por artículo: lo que se cobró contra lo que costó.
 *
 * ── En qué se diferencia del margen de la lista de precios ───────────────
 *
 * Aquel reporte mide el margen TEÓRICO: el precio que dice la lista contra
 * el costo promedio de hoy. Este mide el margen que de verdad ocurrió:
 *
 *   ingreso  = lo facturado, ya neto de descuentos (sales_document_lines)
 *   costo    = lo que el kardex descargó al vender (stock_journals)
 *
 * La diferencia entre los dos reportes es justamente lo que se perdió en
 * descuentos autorizados, en ventas a precio pactado y en costos que
 * subieron después de fijar la lista. Por eso valen los dos: uno dice cuánto
 * debería dejar cada artículo, el otro cuánto dejó.
 *
 * ── Por qué el costo NO se toma del precio unitario de la factura ────────
 *
 * La factura no registra costo, registra precio. El costo de una venta es lo
 * que el motor de inventario descargó por esa salida, al promedio vigente en
 * ese momento — no al promedio de hoy. Tomarlo del kardex es lo que hace que
 * este reporte cuadre con el estado de resultados; recalcularlo con el
 * promedio actual daría un margen que no coincide con ninguna cuenta.
 *
 * ── Los servicios no aparecen ────────────────────────────────────────────
 *
 * Una línea de servicio factura ingreso pero no descarga inventario: su
 * "margen" sería del 100% y contaminaría el ranking. Este reporte es de
 * mercancía.
 */
class ItemProfitabilityReport implements InventoryReport
{
    use Concerns\FiltersDateRange;

    public function code(): string
    {
        return 'item-profitability';
    }

    public function label(): string
    {
        return 'Rentabilidad por artículo';
    }

    public function description(): string
    {
        return 'Lo facturado de cada artículo en el período contra lo que el kardex descargó como costo, con la utilidad resultante.';
    }

    public function decision(): string
    {
        return 'Qué deja plata de verdad y qué se vende mucho dejando poco: lo que más factura no siempre es lo que más rinde.';
    }

    public function group(): string
    {
        return 'Rentabilidad';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_month'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('order', 'Ordenar por', ReportFilter::SELECT, default: 'profit', options: [
                'profit' => 'Mayor utilidad primero',
                'margin' => 'Menor margen primero (lo que menos rinde)',
                'revenue' => 'Mayor facturación primero',
            ]),
            new ReportFilter('search', 'Código o nombre', ReportFilter::TEXT),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $revenue = $this->revenueByItem($company, $filters);
        $cost = $this->costByItem($company, $filters);

        $itemIds = array_unique([...array_keys($revenue), ...array_keys($cost)]);

        if ($itemIds === []) {
            return new ReportResult($this->columns(), [], notes: $this->notes($filters));
        }

        $items = DB::table('items')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->where('items.company_id', $company->id)
            ->whereIn('items.id', $itemIds)
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('items.code', 'like', "%{$v}%")
                ->orWhere('items.name', 'like', "%{$v}%")))
            ->get(['items.id', 'items.code', 'items.name', 'item_groups.name as group_name']);

        $rows = $items->map(function ($item) use ($revenue, $cost) {
            $sold = (float) ($revenue[$item->id]['quantity'] ?? 0);
            $income = (float) ($revenue[$item->id]['amount'] ?? 0);
            $cogs = (float) ($cost[$item->id] ?? 0);
            $profit = $income - $cogs;

            return [
                'code' => $item->code,
                'name' => $item->name,
                'group_name' => $item->group_name,
                'quantity_sold' => $sold,
                'revenue' => $income,
                'cost' => $cogs,
                'profit' => $profit,
                // Sin ingreso no hay margen porcentual: dividir por cero
                // daría infinito, y un cero se leería como "no dejó nada".
                'margin_percent' => $income > 0 ? ($profit / $income) * 100 : null,
                'unit_profit' => $sold > 0 ? $profit / $sold : null,
                'signal' => $this->signal($income, $profit),
            ];
        });

        $rows = match ($filters['order'] ?? 'profit') {
            'margin' => $rows->sortBy(fn (array $r) => $r['margin_percent'] ?? PHP_INT_MAX),
            'revenue' => $rows->sortByDesc('revenue'),
            default => $rows->sortByDesc('profit'),
        };

        $rows = $rows->values()->all();

        $totalRevenue = array_sum(array_column($rows, 'revenue'));
        $totalProfit = array_sum(array_column($rows, 'profit'));

        return new ReportResult(
            columns: $this->columns(),
            rows: $rows,
            // El margen del conjunto es utilidad total sobre ingreso total,
            // no el promedio de los márgenes: promediar porcentajes de
            // volúmenes distintos da un número que no existe.
            totals: ['margin_percent' => $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0],
            notes: $this->notes($filters),
        );
    }

    /**
     * @return ReportColumn[]
     */
    private function columns(): array
    {
        return [
            new ReportColumn('code', 'Código'),
            new ReportColumn('name', 'Artículo', width: 28),
            new ReportColumn('group_name', 'Grupo'),
            new ReportColumn('quantity_sold', 'Vendido', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('revenue', 'Facturado', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('cost', 'Costo', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('profit', 'Utilidad', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('margin_percent', 'Margen %', ReportColumn::PERCENT),
            new ReportColumn('unit_profit', 'Utilidad por unidad', ReportColumn::MONEY),
            new ReportColumn('signal', 'Señal', width: 16),
        ];
    }

    /**
     * Lo facturado, neto de descuentos y sin impuesto. Las notas de crédito
     * restan: una devolución no dejó utilidad.
     *
     * @return array<int, array{quantity: float, amount: float}>
     */
    private function revenueByItem(Company $company, array $filters): array
    {
        $query = DB::table('sales_document_lines')
            ->join('sales_documents', 'sales_documents.id', '=', 'sales_document_lines.sales_document_id')
            ->where('sales_documents.company_id', $company->id);

        return $this->inDateRange($query, 'sales_documents.document_date', $filters['from'], $filters['to'])
            ->whereNotNull('sales_document_lines.item_id')
            ->where('sales_document_lines.is_service', false)
            ->groupBy('sales_document_lines.item_id')
            ->get([
                'sales_document_lines.item_id',
                // La nota de crédito (03) resta; factura y tiquete suman.
                DB::raw("SUM(CASE WHEN sales_documents.fiscal_document_type = '03' THEN -sales_document_lines.quantity ELSE sales_document_lines.quantity END) as quantity"),
                DB::raw("SUM(CASE WHEN sales_documents.fiscal_document_type = '03' THEN -sales_document_lines.subtotal ELSE sales_document_lines.subtotal END) as amount"),
            ])
            ->mapWithKeys(fn ($row) => [
                (int) $row->item_id => ['quantity' => (float) $row->quantity, 'amount' => (float) $row->amount],
            ])
            ->all();
    }

    /**
     * Lo que el kardex descargó por esas ventas, al promedio vigente en cada
     * momento. Una devolución de cliente vuelve a entrar y resta costo.
     *
     * @return array<int, float>
     */
    private function costByItem(Company $company, array $filters): array
    {
        $query = DB::table('stock_journals')
            ->join('inventory_document_lines', 'inventory_document_lines.id', '=', 'stock_journals.inventory_document_line_id')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('stock_journals.company_id', $company->id)
            ->whereIn('inventory_documents.operation', ['sales_issue', 'sales_return']);

        return $this->inDateRange($query, 'stock_journals.posting_date', $filters['from'], $filters['to'])
            ->groupBy('stock_journals.item_id')
            ->get([
                'stock_journals.item_id',
                DB::raw("SUM(CASE WHEN stock_journals.direction = 'out' THEN stock_journals.total_cost_local ELSE -stock_journals.total_cost_local END) as cost"),
            ])
            ->mapWithKeys(fn ($row) => [(int) $row->item_id => (float) $row->cost])
            ->all();
    }

    private function notes(array $filters): array
    {
        return [
            "Ventas facturadas entre {$filters['from']} y {$filters['to']}.",
            'El ingreso va neto de descuentos y sin impuesto; las notas de crédito restan.',
            'El costo sale del kardex, al promedio vigente cuando se vendió — no al promedio de hoy. Por eso este reporte cuadra con el estado de resultados.',
            'Solo mercancía: una línea de servicio factura ingreso pero no descarga inventario, y su margen del 100% contaminaría el ranking.',
            'El margen del pie es el del conjunto: utilidad total sobre ingreso total, no el promedio de los porcentajes.',
        ];
    }

    private function signal(float $revenue, float $profit): string
    {
        if ($revenue <= 0) {
            return '';
        }

        $margin = ($profit / $revenue) * 100;

        return match (true) {
            $margin < 0 => 'Pérdida',
            $margin < 10 => 'Margen delgado',
            default => '',
        };
    }
}
