<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\IncomeStatementExporter;
use App\Domains\Accounting\Services\IncomeStatementService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomeStatementController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, IncomeStatementService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);

        return Inertia::render('Reports/IncomeStatement', [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'hideZero' => $validated['hide_zero'],
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        IncomeStatementService $service,
        IncomeStatementExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);
        $header = $headerFactory->make($company, $request->user(), 'Estado de resultados', $this->paramsSummary($validated));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'estado-resultados.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        IncomeStatementService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);
        $header = $headerFactory->make($company, $request->user(), 'Estado de resultados', $this->paramsSummary($validated));

        return Pdf::loadView('reports.income-statement', compact('header', 'result'))
            ->download('estado-resultados.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $request->merge([
            'from' => $request->query('from', now()->startOfMonth()->format('Y-m-d')),
            'to' => $request->query('to', now()->endOfMonth()->format('Y-m-d')),
        ]);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'hide_zero' => ['nullable', 'boolean'],
        ]);

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'hide_zero' => $request->boolean('hide_zero', true),
        ];
    }

    private function paramsSummary(array $filters): string
    {
        return "Del {$filters['from']} al {$filters['to']}"
            .' — '.($filters['hide_zero'] ? 'ocultando cuentas sin movimiento' : 'incluyendo todas las cuentas');
    }
}
