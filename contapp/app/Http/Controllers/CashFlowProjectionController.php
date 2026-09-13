<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Services\CashFlowProjectionExporter;
use App\Domains\BusinessPartners\Services\CashFlowProjectionService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashFlowProjectionController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, CashFlowProjectionService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['buckets'] ?? null);

        return Inertia::render('Reports/CashFlowProjection', [
            'asOf' => $validated['as_of'],
            'buckets' => $validated['buckets'] ?? null,
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        CashFlowProjectionService $service,
        CashFlowProjectionExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['buckets'] ?? null);
        $header = $headerFactory->make($company, $request->user(), 'Proyección de cobros y pagos', $this->paramsSummary($validated));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'proyeccion-cobros-pagos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        CashFlowProjectionService $service,
        ReportHeaderFactory $headerFactory,
    ): \Illuminate\Http\Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['buckets'] ?? null);
        $header = $headerFactory->make($company, $request->user(), 'Proyección de cobros y pagos', $this->paramsSummary($validated));

        return Pdf::loadView('reports.cash-flow-projection', compact('header', 'result'))
            ->download('proyeccion-cobros-pagos.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $request->merge(['as_of' => $request->query('as_of', now()->format('Y-m-d'))]);

        return $request->validate([
            'as_of' => ['required', 'date'],
            // Cortes de días personalizados (ej. "10,40") — opcional, vacío
            // cae al estándar 15/30/60/90 (ver DayBucketScheme).
            'buckets' => ['nullable', 'string', 'max:120', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$/'],
        ]);
    }

    private function paramsSummary(array $filters): string
    {
        $bucketsNote = ! empty($filters['buckets']) ? " — cortes: {$filters['buckets']} días" : '';

        return "Al {$filters['as_of']}{$bucketsNote}";
    }
}
