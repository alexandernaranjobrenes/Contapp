<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\MultiCompanyComparisonExporter;
use App\Domains\Accounting\Services\MultiCompanyComparisonService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MultiCompanyComparisonController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, MultiCompanyComparisonService $service): InertiaResponse
    {
        $filters = $this->validateFilters($request);
        $companies = $this->resolveGroupCompanies($request, $currentCompany);

        $result = $service->build($companies, $filters['as_of'], $filters['from'], $filters['to']);

        return Inertia::render('Reports/MultiCompanyComparison', [
            'asOf' => $filters['as_of'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        MultiCompanyComparisonService $service,
        MultiCompanyComparisonExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $filters = $this->validateFilters($request);
        $companies = $this->resolveGroupCompanies($request, $currentCompany);
        $currentCompanyModel = Company::findOrFail($currentCompany->id());

        $result = $service->build($companies, $filters['as_of'], $filters['from'], $filters['to']);
        $header = $headerFactory->make($currentCompanyModel, $request->user(), 'Comparativo de empresas del grupo', $this->paramsSummary($filters));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'comparativo-empresas.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        MultiCompanyComparisonService $service,
        ReportHeaderFactory $headerFactory,
    ): \Illuminate\Http\Response {
        $filters = $this->validateFilters($request);
        $companies = $this->resolveGroupCompanies($request, $currentCompany);
        $currentCompanyModel = Company::findOrFail($currentCompany->id());

        $result = $service->build($companies, $filters['as_of'], $filters['from'], $filters['to']);
        $header = $headerFactory->make($currentCompanyModel, $request->user(), 'Comparativo de empresas del grupo', $this->paramsSummary($filters));

        return Pdf::loadView('reports.multi-company-comparison', compact('header', 'result'))
            ->download('comparativo-empresas.pdf');
    }

    /**
     * El "grupo" es el conjunto de compañías que comparten la misma
     * licencia que la compañía activa — mismo criterio de agrupación que
     * usa el propio módulo de licenciamiento (una licencia = un cliente/
     * grupo empresarial, CLAUDE.md secc. 13) — restringido además a las
     * compañías a las que el usuario autenticado realmente pertenece
     * (mismo chequeo que ya usa CompanySwitchController): un comparativo
     * nunca debe filtrar cifras de una compañía a la que el usuario no
     * tiene acceso, aunque comparta la misma licencia.
     *
     * Sin licencia (license_id null, ej. compañía creada por seeder/tinker
     * sin pasar por activación) no hay "grupo" real que agrupar — un
     * `where('license_id', null)` agruparía a CIEGAS cualquier otra
     * compañía sin licencia, que no tiene nada que ver con esta. En ese
     * caso el comparativo muestra solo la compañía activa.
     *
     * @return Collection<int, Company>
     */
    private function resolveGroupCompanies(Request $request, CurrentCompany $currentCompany): Collection
    {
        $current = Company::findOrFail($currentCompany->id());

        $query = $request->user()->companies()->with('localCurrency');

        if ($current->license_id === null) {
            $query->whereKey($current->id);
        } else {
            $query->where('license_id', $current->license_id);
        }

        return $query->orderBy('legal_name')->get();
    }

    private function validateFilters(Request $request): array
    {
        $request->merge([
            'as_of' => $request->query('as_of', now()->format('Y-m-d')),
            'from' => $request->query('from', now()->startOfMonth()->format('Y-m-d')),
            'to' => $request->query('to', now()->endOfMonth()->format('Y-m-d')),
        ]);

        return $request->validate([
            'as_of' => ['required', 'date'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
    }

    private function paramsSummary(array $filters): string
    {
        return "Balance al {$filters['as_of']} — Actividad del {$filters['from']} al {$filters['to']}";
    }
}
