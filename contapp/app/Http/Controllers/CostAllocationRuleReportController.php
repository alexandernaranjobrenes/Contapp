<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\CostAllocationRuleReportExporter;
use App\Domains\Accounting\Services\CostAllocationRuleReportService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostAllocationRuleReportController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, CostAllocationRuleReportService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to']);

        return Inertia::render('Reports/CostAllocationRuleReport', [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        CostAllocationRuleReportService $service,
        CostAllocationRuleReportExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to']);
        $header = $headerFactory->make($company, $request->user(), 'Normas de reparto: distribución real vs. definida', $this->paramsSummary($validated));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'normas-de-reparto.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        CostAllocationRuleReportService $service,
        ReportHeaderFactory $headerFactory,
    ): \Illuminate\Http\Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to']);
        $header = $headerFactory->make($company, $request->user(), 'Normas de reparto: distribución real vs. definida', $this->paramsSummary($validated));

        return Pdf::loadView('reports.cost-allocation-rule', compact('header', 'result'))
            ->download('normas-de-reparto.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    private function paramsSummary(array $filters): string
    {
        return match (true) {
            $filters['from'] !== null && $filters['to'] !== null => "Del {$filters['from']} al {$filters['to']}",
            $filters['from'] !== null => "Desde {$filters['from']}",
            $filters['to'] !== null => "Hasta {$filters['to']}",
            default => 'Sin rango de fechas (histórico completo)',
        };
    }
}
