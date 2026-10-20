<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\DataTransferObjects\AgingResult;
use App\Domains\BusinessPartners\Models\BusinessPartner;
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

        $result = $this->buildResult($service, $company, $validated);

        return Inertia::render('Reports/Aging', [
            'asOf' => $validated['as_of'],
            'partnerType' => $validated['partner_type'],
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
        AgingService $service,
        AgingExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $validated = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->buildResult($service, $company, $validated);
        $header = $headerFactory->make($company, $request->user(), 'Antigüedad de saldos', $this->paramsSummary($validated));
        $includeDocuments = (bool) ($validated['include_documents'] ?? false);

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result, $includeDocuments),
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

        $result = $this->buildResult($service, $company, $validated);
        $header = $headerFactory->make($company, $request->user(), 'Antigüedad de saldos', $this->paramsSummary($validated));
        $includeDocuments = (bool) ($validated['include_documents'] ?? false);

        return Pdf::loadView('reports.aging', compact('header', 'result', 'includeDocuments'))
            ->download('antiguedad-saldos.pdf');
    }

    private function buildResult(AgingService $service, Company $company, array $filters): AgingResult
    {
        return $service->build(
            $company,
            $filters['as_of'],
            $filters['partner_type'],
            $filters['buckets'] ?? null,
            $filters['business_partner_id'] ?? null,
            $filters['partner_code_from'] ?? null,
            $filters['partner_code_to'] ?? null,
        );
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
            // Un socio puntual O un rango de códigos — alternativos en
            // pantalla (ver Aging.vue), pero independientes acá: nada impide
            // combinarlos si alguien arma la URL a mano.
            'business_partner_id' => ['nullable', 'integer'],
            'partner_code_from' => ['nullable', 'string', 'max:50'],
            'partner_code_to' => ['nullable', 'string', 'max:50'],
            // Debajo de cada socio, el detalle de sus documentos — en el
            // XLSX, el PDF y la impresión (no en pantalla: ahí se abre al
            // tocar el socio, ver AgingGroupTable.vue).
            'include_documents' => ['nullable', 'boolean'],
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

        $partnerNote = '';
        if (! empty($filters['business_partner_id'])) {
            $partner = BusinessPartner::find($filters['business_partner_id']);
            $partnerNote = $partner ? " — socio: {$partner->code} — {$partner->name}" : '';
        } elseif (! empty($filters['partner_code_from']) || ! empty($filters['partner_code_to'])) {
            $partnerNote = ' — socios: '.($filters['partner_code_from'] ?? 'inicio').' a '.($filters['partner_code_to'] ?? 'fin');
        }

        return "Al {$filters['as_of']} — {$label}{$bucketsNote}{$partnerNote}";
    }
}
