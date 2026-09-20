<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Services\AgingExporter;
use App\Domains\BusinessPartners\Services\AgingService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgingController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, AgingService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['partner_type'], $validated['buckets'] ?? null);

        return Inertia::render('Reports/Aging', [
            'asOf' => $validated['as_of'],
            'partnerType' => $validated['partner_type'],
            'buckets' => $validated['buckets'] ?? null,
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        AgingService $service,
        AgingExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['partner_type'], $validated['buckets'] ?? null);
        $header = $headerFactory->make($company, $request->user(), 'Antigüedad de saldos', $this->paramsSummary($validated));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'antiguedad-saldos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        AgingService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $validated['as_of'], $validated['partner_type'], $validated['buckets'] ?? null);
        $header = $headerFactory->make($company, $request->user(), 'Antigüedad de saldos', $this->paramsSummary($validated));

        return Pdf::loadView('reports.aging', compact('header', 'result'))
            ->download('antiguedad-saldos.pdf');
    }

    private function validateFilters(Request $request): array
    {
        $request->merge([
            'as_of' => $request->query('as_of', now()->format('Y-m-d')),
            'partner_type' => $request->query('partner_type', 'both'),
        ]);

        return $request->validate([
            'as_of' => ['required', 'date'],
            'partner_type' => ['required', 'in:both,client,supplier'],
            // Cortes de días personalizados (ej. "15,45,90,180") — opcional,
            // vacío/ausente cae al estándar 30/60/90 (ver DayBucketScheme).
            // Se valida el FORMATO acá (números y comas nada más); un valor
            // sin cortes usables ya lo resuelve DayBucketScheme::fromInput()
            // solo, cayendo al estándar sin reventar la consulta.
            'buckets' => ['nullable', 'string', 'max:120', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$/'],
        ]);
    }

    private function paramsSummary(array $filters): string
    {
        $label = match ($filters['partner_type']) {
            'client' => 'clientes',
            'supplier' => 'proveedores',
            default => 'clientes y proveedores',
        };

        $bucketsNote = ! empty($filters['buckets']) ? " — cortes: {$filters['buckets']} días" : '';

        return "Al {$filters['as_of']} — {$label}{$bucketsNote}";
    }
}
