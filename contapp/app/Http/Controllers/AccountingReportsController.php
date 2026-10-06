<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Reports\AccountingReport;
use App\Domains\Accounting\Reports\AccountingReportRegistry;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Reports\HasSelectableColumns;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;
use App\Domains\Reporting\Services\TabularReportExporter;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las cinco puertas de los libros legales: el índice, la consulta en pantalla,
 * el XLSX, el PDF y la impresión.
 *
 * Las cinco sirven a cualquier reporte del registro de contabilidad. Un
 * reporte nuevo no agrega una línea acá.
 *
 * ── Por qué imprimir es el PDF y no la pantalla ────────────────────────
 *
 * Un libro mayor o un diario se imprimen para archivarse o para entregarse, y
 * tienen que salir con el encabezado de la empresa, el rango con que se
 * corrieron y quién los generó. Eso ya lo hace el PDF; imprimir la pantalla
 * daría una hoja anónima que no sirve como respaldo.
 */
class AccountingReportsController extends Controller
{
    public function __construct(private readonly AccountingReportRegistry $registry) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Reports/Index', [
            'groups' => collect($this->registry->grouped())
                ->map(fn (array $reports) => array_map(fn (AccountingReport $r) => [
                    'code' => $r->code(),
                    'label' => $r->label(),
                    'description' => $r->description(),
                    'decision' => $r->decision(),
                ], $reports)),
            // Los reportes financieros tienen su propia pantalla desde antes:
            // se enlazan para que este sea el único lugar al que haya que
            // venir a buscar una salida de contabilidad.
            'related' => [
                ['label' => 'Balance de comprobación', 'route' => 'reports.trial-balance.index',
                    'decision' => 'Si el total de débitos y créditos cuadra, cuenta por cuenta.'],
                ['label' => 'Estado de resultados', 'route' => 'reports.income-statement.index',
                    'decision' => 'Cuánto se ganó o se perdió en el período.'],
                ['label' => 'Balance general', 'route' => 'reports.balance-sheet.index',
                    'decision' => 'Qué tiene y qué debe la empresa a una fecha.'],
                ['label' => 'Registro por tipo de documento', 'route' => 'reports.document-type-register.index',
                    'decision' => 'Qué documentos se emitieron de cada tipo y con qué consecutivo.'],
            ],
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $definition->build($this->company(), $filters);

        return Inertia::render('Accounting/Reports/Show', [
            'report' => [
                'code' => $definition->code(),
                'label' => $definition->label(),
                'description' => $definition->description(),
                'decision' => $definition->decision(),
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
            'availableColumns' => $definition instanceof HasSelectableColumns
                ? array_map(fn (ReportColumn $c) => ['key' => $c->key, 'label' => $c->label],
                    $definition->availableColumns())
                : null,
            'selectedColumns' => null,
            'columns' => array_map(fn (ReportColumn $c) => [
                'key' => $c->key,
                'label' => $c->label,
                'format' => $c->format,
                'numeric' => $c->isNumeric(),
            ], $result->columns),
            // Las filas van ya PRESENTADAS por la misma clase que las presenta
            // en el XLSX y el PDF: si la pantalla formateara por su cuenta, el
            // mismo número se vería distinto según dónde se mire.
            'rows' => $this->present($result),
            'totals' => $this->presentTotals($result),
            'notes' => $result->notes,
            'rowCount' => count($result->rows),
        ]);
    }

    public function export(
        Request $request,
        string $report,
        TabularReportExporter $exporter,
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
        return $this->pdf($request, $report, $headerFactory)->download($report.'.pdf');
    }

    /** El mismo PDF, abierto en el navegador para imprimir. */
    public function print(Request $request, string $report, ReportHeaderFactory $headerFactory): HttpResponse
    {
        return $this->pdf($request, $report, $headerFactory)->stream($report.'.pdf');
    }

    private function pdf(Request $request, string $report, ReportHeaderFactory $headerFactory): \Barryvdh\DomPDF\PDF
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $definition->build($this->company(), $filters);

        $header = $headerFactory->make(
            $this->company(), $request->user(), $definition->label(), $this->summary($definition, $filters)
        );

        $totals = $result->computedTotals();

        return Pdf::loadView('reports.tabular', compact('header', 'result', 'totals'))
            // Horizontal: un mayor lleva once columnas y en vertical no entra.
            ->setPaper('letter', 'landscape');
    }

    private function definitionOrFail(string $report): AccountingReport
    {
        return $this->registry->find($report) ?? abort(404, 'Ese reporte de contabilidad no existe.');
    }

    private function company(): Company
    {
        return Company::findOrFail(app(CurrentCompany::class)->id());
    }

    /**
     * Valida lo que llegó por la URL contra lo que el reporte declaró, y
     * rellena los valores por defecto.
     *
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request, AccountingReport $definition): array
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
     * Los defaults relativos se resuelven al consultar y no al declarar: si
     * fueran fechas fijas, un reporte guardado en enero seguiría pidiendo
     * enero en marzo.
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

    /** @return array<int, array<string, string>> */
    private function present(ReportResult $result): array
    {
        return array_map(
            fn (array $row) => collect($result->columns)
                ->mapWithKeys(fn (ReportColumn $c) => [$c->key => $c->display($row[$c->key] ?? null)])
                ->all(),
            $result->rows
        );
    }

    /** @return array<string, string> */
    private function presentTotals(ReportResult $result): array
    {
        $totals = $result->computedTotals();

        return collect($result->columns)
            ->filter(fn (ReportColumn $c) => array_key_exists($c->key, $totals))
            ->mapWithKeys(fn (ReportColumn $c) => [$c->key => $c->display($totals[$c->key])])
            ->all();
    }

    /**
     * El resumen de parámetros que sale impreso en el encabezado. Sin él, un
     * libro archivado no dice de qué rango es.
     */
    private function summary(AccountingReport $definition, array $filters): string
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
            'posting_accounts' => ChartOfAccount::find($value)?->code ?? (string) $value,
            'document_types' => DocumentType::find($value)?->code ?? (string) $value,
            'business_partners' => BusinessPartner::find($value)?->name ?? (string) $value,
            default => (string) $value,
        };
    }

    /** @return array<string, string>|null */
    private function optionsFor(?string $source): ?array
    {
        return match ($source) {
            // Solo las que aceptan movimiento: una cuenta de mayor no tiene
            // líneas propias y ofrecerla daría siempre un reporte vacío.
            'posting_accounts' => ChartOfAccount::where('accepts_posting', true)
                ->orderBy('code')->get(['id', 'code', 'description_es'])
                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->code.' — '.$a->description_es])->all(),
            'document_types' => DocumentType::orderBy('code')->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (DocumentType $d) => [$d->id => $d->code.' — '.$d->name])->all(),
            'business_partners' => BusinessPartner::orderBy('code')->limit(2000)
                ->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (BusinessPartner $p) => [$p->id => $p->code.' — '.$p->name])->all(),
            default => null,
        };
    }
}
