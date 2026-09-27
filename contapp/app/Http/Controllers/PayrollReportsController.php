<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\JobPosition;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Reports\PayrollReport;
use App\Domains\Payroll\Reports\PayrollReportRegistry;
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
 * Las cinco puertas de los reportes de planilla: el índice, la consulta en
 * pantalla, el XLSX, el PDF y la impresión.
 *
 * Las cinco sirven a CUALQUIER reporte del registro. Un reporte nuevo no
 * agrega una línea acá — es la misma prueba que en inventario: si hubiera que
 * tocar este archivo por cada reporte, la abstracción no valdría nada.
 *
 * ── Imprimir es el PDF, no la pantalla ──────────────────────────────────
 *
 * En inventario el botón de imprimir usa la pantalla con CSS de impresión, y
 * está bien para una consulta de trabajo. Acá no: un reporte de planilla que
 * se imprime se firma y se archiva, y tiene que salir con el encabezado de la
 * empresa, los filtros con que se corrió y quién lo generó. Eso ya lo hace el
 * PDF, así que imprimir abre el PDF en el navegador en vez de inventar una
 * segunda maqueta que se pueda desincronizar.
 */
class PayrollReportsController extends Controller
{
    public function __construct(private readonly PayrollReportRegistry $registry) {}

    public function index(): Response
    {
        return Inertia::render('Payroll/Reports/Index', [
            'groups' => collect($this->registry->grouped())
                ->map(fn (array $reports) => array_map(fn (PayrollReport $r) => [
                    'code' => $r->code(),
                    'label' => $r->label(),
                    'description' => $r->description(),
                    'decision' => $r->decision(),
                    'selectable_columns' => $r instanceof HasSelectableColumns,
                ], $reports)),
            // Los comprobantes de pago no son un reporte tabular: son un
            // documento por trabajador, con su propia salida y su envío por
            // correo. Se enlazan desde acá para que este sea el único lugar al
            // que haya que venir a buscar una salida de planilla.
            'related' => [
                ['label' => 'Comprobantes de pago', 'route' => 'payroll-periods.index',
                    'decision' => 'El comprobante de cada trabajador, para imprimir o enviar por correo.'],
                ['label' => 'Archivo de pago del banco', 'route' => 'payroll-periods.index',
                    'decision' => 'El archivo que se le sube al banco para acreditar los netos.'],
            ],
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $this->narrow($definition->build($this->company(), $filters), $this->selectedColumns($request, $definition));

        return Inertia::render('Payroll/Reports/Show', [
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
            // El catálogo de columnas solo viaja si el reporte deja
            // escogerlas: la pantalla no ofrece un control que no aplica.
            'availableColumns' => $definition instanceof HasSelectableColumns
                ? array_map(fn (ReportColumn $c) => ['key' => $c->key, 'label' => $c->label],
                    $definition->availableColumns())
                : null,
            'selectedColumns' => $definition instanceof HasSelectableColumns
                ? $this->selectedColumns($request, $definition)
                : null,
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
        $result = $this->narrow($definition->build($this->company(), $filters), $this->selectedColumns($request, $definition));

        $header = $headerFactory->make(
            $this->company(), $request->user(), $definition->label(), $this->summary($definition, $filters)
        );

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'planilla-'.$definition->code().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(Request $request, string $report, ReportHeaderFactory $headerFactory): HttpResponse
    {
        return $this->pdf($request, $report, $headerFactory)
            ->download('planilla-'.$report.'.pdf');
    }

    /**
     * El mismo PDF, abierto en el navegador para imprimir. Un solo documento
     * para archivar y para imprimir: no hay dos maquetas que se puedan
     * desincronizar.
     */
    public function print(Request $request, string $report, ReportHeaderFactory $headerFactory): HttpResponse
    {
        return $this->pdf($request, $report, $headerFactory)
            ->stream('planilla-'.$report.'.pdf');
    }

    private function pdf(Request $request, string $report, ReportHeaderFactory $headerFactory): \Barryvdh\DomPDF\PDF
    {
        $definition = $this->definitionOrFail($report);
        $filters = $this->resolveFilters($request, $definition);
        $result = $this->narrow($definition->build($this->company(), $filters), $this->selectedColumns($request, $definition));

        $header = $headerFactory->make(
            $this->company(), $request->user(), $definition->label(), $this->summary($definition, $filters)
        );

        $totals = $result->computedTotals();

        return Pdf::loadView('reports.tabular', compact('header', 'result', 'totals'))
            // Horizontal siempre: una planilla tiene de diez a veinte columnas
            // y en vertical no entra ninguna.
            ->setPaper('letter', 'landscape');
    }

    private function definitionOrFail(string $report): PayrollReport
    {
        return $this->registry->find($report) ?? abort(404, 'Ese reporte de planilla no existe.');
    }

    private function company(): Company
    {
        return Company::findOrFail(app(CurrentCompany::class)->id());
    }

    /**
     * Qué columnas quedan, cuando el reporte deja escogerlas.
     *
     * Una selección vacía cae en las de por defecto y NO en «ninguna»: un
     * reporte sin columnas es una pantalla en blanco que parece un error del
     * sistema.
     *
     * @return string[]|null null cuando el reporte no deja escoger
     */
    private function selectedColumns(Request $request, PayrollReport $definition): ?array
    {
        if (! $definition instanceof HasSelectableColumns) {
            return null;
        }

        $available = array_map(fn (ReportColumn $c) => $c->key, $definition->availableColumns());

        $requested = $request->query('columns');

        $keys = is_string($requested) ? explode(',', $requested) : (is_array($requested) ? $requested : []);

        // Se descarta lo que el reporte no declara: una clave inventada en la
        // URL no puede producir una columna fantasma ni un error.
        $keys = array_values(array_intersect($keys, $available));

        return $keys === [] ? $definition->defaultColumns() : $keys;
    }

    /**
     * Deja en el resultado solo las columnas escogidas, en el ORDEN en que el
     * reporte las declara.
     *
     * El orden es del reporte y no del usuario a propósito: dejar reordenar
     * convierte cada exportación en una maqueta distinta, y dos archivos del
     * mismo reporte que no se pueden comparar columna a columna dejan de
     * servir para conciliar.
     *
     * @param  string[]|null  $keys
     */
    private function narrow(ReportResult $result, ?array $keys): ReportResult
    {
        if ($keys === null) {
            return $result;
        }

        $columns = array_values(array_filter(
            $result->columns,
            fn (ReportColumn $c) => in_array($c->key, $keys, true)
        ));

        return new ReportResult($columns, $result->rows, $result->totals, $result->notes);
    }

    /**
     * Valida lo que llegó por la URL contra lo que el reporte declaró, y
     * rellena los valores por defecto. Un filtro que el reporte no declara
     * simplemente no existe.
     *
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request, PayrollReport $definition): array
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
     *
     * `last_calculated_period` se deja en null a propósito: lo resuelve el
     * reporte con su propia consulta, porque «el último calculado» depende de
     * la compañía y del estado de sus períodos.
     */
    private function defaultValue(ReportFilter $filter): mixed
    {
        return match ($filter->default) {
            'today' => now()->format('Y-m-d'),
            'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
            'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
            'last_calculated_period' => null,
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
     * El resumen de parámetros que sale impreso en el encabezado. Se arma de la
     * declaración, así que ningún reporte puede olvidarse de decir con qué
     * filtros se corrió — que es lo que vuelve inútil un PDF archivado.
     */
    private function summary(PayrollReport $definition, array $filters): string
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
            'payroll_periods' => PayrollPeriod::find($value)?->name ?? (string) $value,
            'cost_centers' => CostCenter::find($value)?->code ?? (string) $value,
            'departments' => Department::find($value)?->name ?? (string) $value,
            'job_positions' => JobPosition::find($value)?->name ?? (string) $value,
            'employees' => Employee::find($value)?->fullName() ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function optionsFor(?string $source): ?array
    {
        return match ($source) {
            // Solo los que ya tienen boletas: ofrecer uno abierto es ofrecer
            // un reporte en blanco.
            'payroll_periods' => PayrollPeriod::whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
                ->orderByDesc('end_date')->orderByDesc('id')->limit(60)
                ->get(['id', 'name', 'end_date'])
                ->mapWithKeys(fn (PayrollPeriod $p) => [$p->id => $p->name])->all(),
            'cost_centers' => CostCenter::orderBy('code')->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (CostCenter $c) => [$c->id => $c->code.' — '.$c->name])->all(),
            'departments' => Department::orderBy('name')->pluck('name', 'id')->all(),
            'job_positions' => JobPosition::orderBy('name')->pluck('name', 'id')->all(),
            'employees' => Employee::orderBy('code')->limit(2000)
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2'])
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->code.' — '.$e->fullName()])->all(),
            default => null,
        };
    }
}
