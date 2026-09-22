<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\PriceList;
use Illuminate\Support\Facades\DB;

/**
 * Los precios de una lista, puestos al lado del costo.
 *
 * ── Por qué el margen y no solo el precio ────────────────────────────────
 *
 * Una lista de precios sola no permite decidir nada: dice cuánto se cobra,
 * no si conviene. El número que cambia decisiones es el margen, y para eso
 * hay que traer el costo promedio, que vive en el artículo. Es el único
 * lugar del módulo donde precio y costo se encuentran — y se encuentran acá,
 * en un reporte, precisamente para no mezclarlos en el motor de costeo.
 *
 * ── El margen se calcula sobre el PRECIO, no sobre el costo ──────────────
 *
 *     margen % = (precio − costo) / precio
 *
 * Es la convención comercial: "30% de margen" significa que de cada ₡100
 * cobrados, ₡30 quedan. Sobre el costo sería el *markup*, otro número
 * distinto y más grande, y confundirlos es una de las formas clásicas de
 * fijar precios que no dan.
 *
 * ── Cuándo el margen no significa nada ───────────────────────────────────
 *
 * Si la lista está en otra moneda que la local, comparar contra un costo en
 * colones daría un número sin sentido: se muestra la lista pero sin margen,
 * y el reporte lo dice en su nota. Un costo en cero es "nunca entró
 * mercancía", no "es gratis", y tampoco produce margen.
 */
class PriceListReport implements InventoryReport
{
    public function code(): string
    {
        return 'price-list';
    }

    public function label(): string
    {
        return 'Listas de precios y margen';
    }

    public function description(): string
    {
        return 'Los precios de una lista junto al costo promedio de cada artículo, con el margen en importe y en porcentaje.';
    }

    public function decision(): string
    {
        return 'Qué se está vendiendo con margen delgado o negativo, y qué artículos del catálogo todavía no tienen precio.';
    }

    public function group(): string
    {
        return 'Rentabilidad';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('price_list_id', 'Lista de precios', ReportFilter::SELECT, optionSource: 'price_lists',
                hint: 'Sin elegir una, se usa la predeterminada de la compañía.'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('show', 'Mostrar', ReportFilter::SELECT, default: 'priced', options: [
                'priced' => 'Solo artículos con precio en la lista',
                'all' => 'Todo el catálogo de venta (revela los que faltan)',
                'below_cost' => 'Solo lo que se vende bajo costo',
                'thin_margin' => 'Solo margen menor al 15%',
            ]),
            new ReportFilter('search', 'Código o nombre', ReportFilter::TEXT),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $list = $this->resolveList($company, $filters);

        if ($list === null) {
            return new ReportResult(
                columns: $this->columns(),
                rows: [],
                notes: ['No hay ninguna lista de precios seleccionada ni predeterminada en esta compañía.'],
            );
        }

        $comparable = $list->currency_id === $company->local_currency_id;
        $show = $filters['show'] ?? 'priced';

        $rows = DB::table('items')
            ->leftJoin('price_list_items', function ($join) use ($list) {
                $join->on('price_list_items.item_id', '=', 'items.id')
                    ->where('price_list_items.price_list_id', '=', $list->id);
            })
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->where('items.company_id', $company->id)
            ->where('items.status', 'active')
            ->where('items.is_sales_item', true)
            ->when($show !== 'all', fn ($q) => $q->whereNotNull('price_list_items.id'))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('items.code', 'like', "%{$v}%")
                ->orWhere('items.name', 'like', "%{$v}%")))
            ->orderBy('items.code')
            ->get([
                'items.code', 'items.name', 'items.avg_cost_local', 'items.is_inventory_item',
                'item_groups.name as group_name',
                'price_list_items.unit_price',
            ])
            ->map(function ($row) use ($comparable) {
                $price = $row->unit_price !== null ? (float) $row->unit_price : null;
                $cost = (float) $row->avg_cost_local;

                // Sin precio, sin costo, o en otra moneda: no hay margen que
                // calcular, y un cero ahí se leería como "margen nulo".
                $hasMargin = $comparable && $price !== null && $price > 0 && $cost > 0;

                return [
                    'code' => $row->code,
                    'name' => $row->name,
                    'group_name' => $row->group_name,
                    'unit_price' => $price,
                    'avg_cost_local' => $row->is_inventory_item ? $cost : null,
                    'margin_amount' => $hasMargin ? $price - $cost : null,
                    'margin_percent' => $hasMargin ? (($price - $cost) / $price) * 100 : null,
                    'flag' => $this->flag($price, $cost, $hasMargin),
                ];
            })
            ->when($show === 'below_cost', fn ($c) => $c->filter(
                fn (array $r) => $r['margin_amount'] !== null && $r['margin_amount'] < 0
            ))
            ->when($show === 'thin_margin', fn ($c) => $c->filter(
                fn (array $r) => $r['margin_percent'] !== null && $r['margin_percent'] < 15
            ))
            ->values()
            ->all();

        $notes = [
            "Lista {$list->code} — {$list->name}, en ".($list->currency?->code ?? '—').
            ($list->prices_include_tax ? ', con IVA incluido.' : ', sin IVA.'),
            'El margen se calcula sobre el precio: (precio − costo) / precio. Sobre el costo sería el markup, un número distinto.',
        ];

        if (! $comparable) {
            $notes[] = 'La lista no está en moneda local, así que no se compara contra el costo promedio: el resultado no tendría significado sin fijar un tipo de cambio.';
        }

        if ($show === 'all') {
            $notes[] = 'Se incluye todo el catálogo de venta: los artículos sin precio son los que hay que completar.';
        }

        return new ReportResult($this->columns(), $rows, notes: $notes);
    }

    /**
     * @return ReportColumn[]
     */
    private function columns(): array
    {
        return [
            new ReportColumn('code', 'Código'),
            new ReportColumn('name', 'Artículo', width: 30),
            new ReportColumn('group_name', 'Grupo'),
            new ReportColumn('unit_price', 'Precio', ReportColumn::MONEY),
            new ReportColumn('avg_cost_local', 'Costo prom.', ReportColumn::MONEY),
            new ReportColumn('margin_amount', 'Margen', ReportColumn::MONEY),
            new ReportColumn('margin_percent', 'Margen %', ReportColumn::PERCENT),
            new ReportColumn('flag', 'Señal', width: 18),
        ];
    }

    private function resolveList(Company $company, array $filters): ?PriceList
    {
        if (filled($filters['price_list_id'] ?? null)) {
            return PriceList::with('currency:id,code')
                ->where('company_id', $company->id)
                ->find($filters['price_list_id']);
        }

        return PriceList::with('currency:id,code')
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * La señal que hace que la fila salte a la vista sin tener que leer el
     * número. Un reporte de 600 artículos sin esto no se revisa.
     */
    private function flag(?float $price, float $cost, bool $hasMargin): string
    {
        if ($price === null) {
            return 'Sin precio';
        }

        if (! $hasMargin) {
            return $cost <= 0 ? 'Sin costo aún' : '';
        }

        $margin = (($price - $cost) / $price) * 100;

        return match (true) {
            $margin < 0 => 'Bajo costo',
            $margin < 15 => 'Margen delgado',
            default => '',
        };
    }
}
