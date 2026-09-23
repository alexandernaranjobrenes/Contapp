<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Reports\InventoryReport;
use App\Domains\Inventory\Reports\InventoryReportRegistry;
use App\Domains\Inventory\Reports\ReportColumn;
use App\Domains\Inventory\Reports\ReportFilter;
use App\Domains\Inventory\Reports\ReportResult;
use App\Domains\Inventory\Services\InventoryReportExporter;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las cuatro puertas de los reportes de inventario: el índice, la consulta
 * en pantalla, el XLSX y el PDF.
 *
 * Los cuatro sirven a CUALQUIER reporte del registro. Un reporte nuevo no
 * agrega una línea acá — esa es la prueba de que la abstracción vale: si
 * hubiera que tocar este archivo por cada reporte, sería una capa de
 * indirección sin beneficio.
 */
class InventoryReportController extends Controller
{
    public function __construct(private readonly InventoryReportRegistry $registry) {}

    /**
     * El índice. Cada reporte se presenta con QUÉ DECISIÓN ayuda a tomar y
     * no solo con su nombre: con ocho reportes, "Rotación y cobertura" no le
     * dice a nadie cuál abrir.
     */
    public function index(): Response
    {
        return Inertia::render('Reports/Inventory/Index', [
            'groups' => collect($this->registry->grouped())
                ->map(fn (array $reports) => array_map(fn (InventoryReport $r) => [
                    'code' => $r->code(),
                    'label' => $r->label(),
                    'description' => $r->description(),
                    'decision' => $r->decision(),
                ], $reports)),
            // Los reportes de inventario que ya existían antes de esta
            // pestaña siguen teniendo su propia pantalla; el índice los
            // enlaza para que este sea el único lugar al que haya que venir.
            'related' => [
                ['label' => 'Existencias valorizadas', 'route' => 'reports.inventory-valuation.index',
                    'decision' => 'Cuánto valía el inventario a una fecha de corte, para cuadrar contra el mayor.'],
                ['label' => 'Antigüedad de inventario', 'route' => 'reports.inventory-aging.index',
                    'decision' => 'Qué mercancía lleva demasiado tiempo sin rotar.'],
                ['label' => 'Vencimiento de lotes', 'route' => 'lot-expiry.index',
                    'decision' => 'Qué lotes están por vencer y hay que despachar primero.'],
                ['label' => 'Sugerencia de compra', 'route' => 'reorder.index',
                    'decision' => 'Qué hay que comprar y cuánto, según mínimos y lo que ya viene en camino.'],
            ],
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $definition->build($this->company(), $filters);

        return Inertia::render('Reports/Inventory/Show', [
            'report' => [
                'code' => $definition->code(),
                'label' => $definition->label(),
                'description' => $definition->description(),
                'decision' => $definition->decision(),
                // Cuántas columnas de la izquierda quedan fijas al
                // desplazar la tabla: lo decide el reporte, no la pantalla.
                'frozen_columns' => $definition->frozenColumns(),
            ],
            'filters' => array_map(fn (ReportFilter $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'type' => $f->type,
                'hint' => $f->hint,
                'options' => $f->options ?? $this->optionsFor($f->optionSource),
            ], $definition->filters()),
            'values' => $filters,
            'columns' => array_map(fn (ReportColumn $c) => [
                'key' => $c->key,
                'label' => $c->label,
                'format' => $c->format,
                'numeric' => $c->isNumeric(),
            ], $result->columns),
            // Las filas van ya PRESENTADAS por la misma clase que las
            // presenta en el XLSX y el PDF: si la pantalla formateara por su
            // cuenta, el mismo número se vería distinto según dónde se mire.
            'rows' => $this->present($result),
            'totals' => $this->presentTotals($result),
            'notes' => $result->notes,
            'rowCount' => count($result->rows),
        ]);
    }

    public function export(
        Request $request,
        string $report,
        InventoryReportExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $definition->build($this->company(), $filters);

        $header = $headerFactory->make(
            $this->company(), $request->user(), $definition->label(), $this->summary($definition, $filters)
        );

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            $definition->code().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(Request $request, string $report, ReportHeaderFactory $headerFactory): HttpResponse
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $definition->build($this->company(), $filters);

        $header = $headerFactory->make(
            $this->company(), $request->user(), $definition->label(), $this->summary($definition, $filters)
        );

        $totals = $result->computedTotals();

        return Pdf::loadView('reports.inventory-generic', compact('header', 'result', 'totals'))
            // Horizontal siempre: estos reportes tienen de ocho a catorce
            // columnas y en vertical no entran.
            ->setPaper('letter', 'landscape')
            ->download($definition->code().'.pdf');
    }

    private function definitionOrFail(string $report): InventoryReport
    {
        return $this->registry->find($report) ?? abort(404, 'Ese reporte de inventario no existe.');
    }

    private function company(): Company
    {
        return Company::findOrFail(app(CurrentCompany::class)->id());
    }

    /**
     * Valida lo que llegó por la URL contra lo que el reporte declaró, y
     * rellena los valores por defecto. Un filtro que el reporte no declara
     * simplemente no existe.
     *
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request, InventoryReport $definition): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $rules = [];
        foreach ($definition->filters() as $filter) {
            $rules[$filter->key] = $filter->validationRules($companyId);
        }

        $validated = $request->validate($rules);

        $filters = [];
        foreach ($definition->filters() as $filter) {
            $value = $validated[$filter->key] ?? null;

            if ($value === null || $value === '') {
                $value = $this->defaultValue($filter);
            }

            $filters[$filter->key] = $filter->type === ReportFilter::BOOLEAN && $value !== null
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : $value;
        }

        return $filters;
    }

    /**
     * Los defaults relativos se resuelven al momento de consultar y no al de
     * declarar: si fueran fechas fijas, un reporte guardado en enero seguiría
     * pidiendo enero en marzo. Mismo criterio que RelativeDate en reportería.
     */
    private function defaultValue(ReportFilter $filter): mixed
    {
        return match ($filter->default) {
            'today' => now()->format('Y-m-d'),
            'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
            'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
            default => $filter->default,
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function present(ReportResult $result): array
    {
        return array_map(
            fn (array $row) => collect($result->columns)
                ->mapWithKeys(fn (ReportColumn $c) => [$c->key => $c->display($row[$c->key] ?? null)])
                ->all(),
            $result->rows
        );
    }

    /**
     * @return array<string, string>
     */
    private function presentTotals(ReportResult $result): array
    {
        $totals = $result->computedTotals();

        return collect($result->columns)
            ->filter(fn (ReportColumn $c) => array_key_exists($c->key, $totals))
            ->mapWithKeys(fn (ReportColumn $c) => [$c->key => $c->display($totals[$c->key])])
            ->all();
    }

    /**
     * El resumen de parámetros que sale impreso en el encabezado. Se arma de
     * la declaración, así que ningún reporte puede olvidarse de declarar con
     * qué filtros se corrió — que es lo que vuelve inútil un PDF archivado.
     */
    private function summary(InventoryReport $definition, array $filters): string
    {
        $parts = [];

        foreach ($definition->filters() as $filter) {
            $value = $filters[$filter->key] ?? null;

            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            $parts[] = $filter->label.': '.$this->readable($filter, $value);
        }

        return $parts === [] ? 'Sin filtros' : implode(' · ', $parts);
    }

    private function readable(ReportFilter $filter, mixed $value): string
    {
        if ($filter->type === ReportFilter::BOOLEAN) {
            return 'sí';
        }

        if ($filter->options !== null) {
            return $filter->options[$value] ?? (string) $value;
        }

        // Un id solo no le dice nada a quien lee el PDF impreso.
        return match ($filter->optionSource) {
            'warehouses' => Warehouse::find($value)?->code ?? (string) $value,
            'item_groups' => ItemGroup::find($value)?->name ?? (string) $value,
            'price_lists' => PriceList::find($value)?->code ?? (string) $value,
            'items' => Item::find($value)?->code ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function optionsFor(?string $source): ?array
    {
        return match ($source) {
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')
                ->pluck('name', 'id')->all(),
            'item_groups' => ItemGroup::orderBy('code')->pluck('name', 'id')->all(),
            'price_lists' => PriceList::where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->mapWithKeys(fn ($l) => [$l->id => $l->code.' — '.$l->name])->all(),
            'items' => Item::where('status', 'active')->where('is_inventory_item', true)
                ->orderBy('code')->limit(1000)
                ->get(['id', 'code', 'name'])
                ->mapWithKeys(fn ($i) => [$i->id => $i->code.' — '.$i->name])->all(),
            default => null,
        };
    }
}
