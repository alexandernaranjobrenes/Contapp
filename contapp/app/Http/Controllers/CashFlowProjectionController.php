<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\DataTransferObjects\CashFlowProjectionResult;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\CashFlowProjectionExporter;
use App\Domains\BusinessPartners\Services\CashFlowProjectionService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashFlowProjectionController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, CashFlowProjectionService $service): InertiaResponse
    {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->buildResult($service, $company, $validated);

        return Inertia::render('Reports/CashFlowProjection', [
            'asOf' => $validated['as_of'],
            'buckets' => $validated['buckets'] ?? null,
            'businessPartnerId' => $validated['business_partner_id'] ?? null,
            'partnerCodeFrom' => $validated['partner_code_from'] ?? null,
            'partnerCodeTo' => $validated['partner_code_to'] ?? null,
            'includeDocuments' => (bool) ($validated['include_documents'] ?? false),
            'businessPartners' => BusinessPartner::orderBy('code')->get(['id', 'code', 'name']),
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

        $result = $this->buildResult($service, $company, $validated);
        $header = $headerFactory->make($company, $request->user(), 'Proyección de cobros y pagos', $this->paramsSummary($validated));
        $includeDocuments = (bool) ($validated['include_documents'] ?? false);

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result, $includeDocuments),
            'proyeccion-cobros-pagos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        CashFlowProjectionService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->buildResult($service, $company, $validated);
        $header = $headerFactory->make($company, $request->user(), 'Proyección de cobros y pagos', $this->paramsSummary($validated));
        $includeDocuments = (bool) ($validated['include_documents'] ?? false);

        return Pdf::loadView('reports.cash-flow-projection', compact('header', 'result', 'includeDocuments'))
            ->download('proyeccion-cobros-pagos.pdf');
    }

    private function buildResult(CashFlowProjectionService $service, Company $company, array $filters): CashFlowProjectionResult
    {
        return $service->build(
            $company,
            $filters['as_of'],
            $filters['buckets'] ?? null,
            $filters['business_partner_id'] ?? null,
            $filters['partner_code_from'] ?? null,
            $filters['partner_code_to'] ?? null,
        );
    }

    private function validateFilters(Request $request): array
    {
        $request->merge(['as_of' => $request->query('as_of', now()->format('Y-m-d'))]);

        return $request->validate([
            'as_of' => ['required', 'date'],
            // Cortes de días personalizados (ej. "10,40") — opcional, vacío
            // cae al estándar 15/30/60/90 (ver DayBucketScheme).
            'buckets' => ['nullable', 'string', 'max:120', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$/'],
            // Un socio puntual O un rango de códigos — ver mismo criterio en
            // AgingController::validateFilters.
            'business_partner_id' => ['nullable', 'integer'],
            'partner_code_from' => ['nullable', 'string', 'max:50'],
            'partner_code_to' => ['nullable', 'string', 'max:50'],
            'include_documents' => ['nullable', 'boolean'],
        ]);
    }

    private function paramsSummary(array $filters): string
    {
        $bucketsNote = ! empty($filters['buckets']) ? " — cortes: {$filters['buckets']} días" : '';

        $partnerNote = '';
        if (! empty($filters['business_partner_id'])) {
            $partner = BusinessPartner::find($filters['business_partner_id']);
            $partnerNote = $partner ? " — socio: {$partner->code} — {$partner->name}" : '';
        } elseif (! empty($filters['partner_code_from']) || ! empty($filters['partner_code_to'])) {
            $partnerNote = ' — socios: '.($filters['partner_code_from'] ?? 'inicio').' a '.($filters['partner_code_to'] ?? 'fin');
        }

        return "Al {$filters['as_of']}{$bucketsNote}{$partnerNote}";
    }
}
