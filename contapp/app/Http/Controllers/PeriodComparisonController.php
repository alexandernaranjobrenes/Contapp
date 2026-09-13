<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\PeriodComparisonExporter;
use App\Domains\Accounting\Services\PeriodComparisonService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PeriodComparisonController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, PeriodComparisonService $service): InertiaResponse
    {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $filters['from_1'], $filters['to_1'], $filters['from_2'], $filters['to_2']);

        return Inertia::render('Reports/PeriodComparison', [
            'from1' => $filters['from_1'],
            'to1' => $filters['to_1'],
            'from2' => $filters['from_2'],
            'to2' => $filters['to_2'],
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        PeriodComparisonService $service,
        PeriodComparisonExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $filters['from_1'], $filters['to_1'], $filters['from_2'], $filters['to_2']);
        $header = $headerFactory->make($company, $request->user(), 'Comparativo entre dos periodos', $this->paramsSummary($filters));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'comparativo-periodos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        PeriodComparisonService $service,
        ReportHeaderFactory $headerFactory,
    ): \Illuminate\Http\Response {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $filters['from_1'], $filters['to_1'], $filters['from_2'], $filters['to_2']);
        $header = $headerFactory->make($company, $request->user(), 'Comparativo entre dos periodos', $this->paramsSummary($filters));

        return Pdf::loadView('reports.period-comparison', compact('header', 'result'))
            ->download('comparativo-periodos.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $request->merge([
            'from_1' => $request->query('from_1', now()->startOfYear()->format('Y-m-d')),
            'to_1' => $request->query('to_1', now()->startOfYear()->endOfMonth()->format('Y-m-d')),
            'from_2' => $request->query('from_2', now()->startOfMonth()->format('Y-m-d')),
            'to_2' => $request->query('to_2', now()->endOfMonth()->format('Y-m-d')),
        ]);

        return $request->validate([
            'from_1' => ['required', 'date'],
            'to_1' => ['required', 'date', 'after_or_equal:from_1'],
            'from_2' => ['required', 'date'],
            'to_2' => ['required', 'date', 'after_or_equal:from_2'],
        ]);
    }

    private function paramsSummary(array $filters): string
    {
        return "Periodo 1: {$filters['from_1']} al {$filters['to_1']} — Periodo 2: {$filters['from_2']} al {$filters['to_2']}";
    }
}
