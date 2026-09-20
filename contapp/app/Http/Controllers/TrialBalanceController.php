<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\TrialBalanceExporter;
use App\Domains\Accounting\Services\TrialBalanceService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrialBalanceController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, TrialBalanceService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);

        return Inertia::render('Reports/TrialBalance', [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'hideZero' => $validated['hide_zero'],
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        TrialBalanceService $service,
        TrialBalanceExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);
        $header = $headerFactory->make($company, $request->user(), 'Balance de comprobación', $this->paramsSummary($validated));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'balance-comprobacion.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        TrialBalanceService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['from'], $validated['to'], $validated['hide_zero']);
        $header = $headerFactory->make($company, $request->user(), 'Balance de comprobación', $this->paramsSummary($validated));

        return Pdf::loadView('reports.trial-balance', compact('header', 'result'))
            ->download('balance-comprobacion.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'hide_zero' => ['nullable', 'boolean'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'hide_zero' => $request->boolean('hide_zero', true),
        ];
    }

    private function paramsSummary(array $filters): string
    {
        $range = match (true) {
            $filters['from'] !== null && $filters['to'] !== null => "Del {$filters['from']} al {$filters['to']}",
            $filters['from'] !== null => "Desde {$filters['from']}",
            $filters['to'] !== null => "Hasta {$filters['to']}",
            default => 'Sin rango de fechas (histórico completo)',
        };

        return $range.' — '.($filters['hide_zero'] ? 'ocultando cuentas sin movimiento' : 'incluyendo todas las cuentas');
    }
}
