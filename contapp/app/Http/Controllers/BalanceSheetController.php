<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\BalanceSheetExporter;
use App\Domains\Accounting\Services\BalanceSheetService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceSheetController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, BalanceSheetService $service): InertiaResponse
    {
        $asOf = $this->resolveAsOf($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $asOf);

        return Inertia::render('Reports/BalanceSheet', [
            'asOf' => $asOf,
            'result' => $result,
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        BalanceSheetService $service,
        BalanceSheetExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $asOf = $this->resolveAsOf($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $asOf);
        $header = $headerFactory->make($company, $request->user(), 'Balance general', "Al {$asOf}");

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'balance-general.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        BalanceSheetService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $asOf = $this->resolveAsOf($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $service->build($company, $asOf);
        $header = $headerFactory->make($company, $request->user(), 'Balance general', "Al {$asOf}");

        return Pdf::loadView('reports.balance-sheet', compact('header', 'result'))
            ->download('balance-general.pdf');
    }

    private function resolveAsOf(Request $request): string
    {
        $request->merge(['as_of' => $request->query('as_of', now()->format('Y-m-d'))]);

        return $request->validate(['as_of' => ['required', 'date']])['as_of'];
    }
}
