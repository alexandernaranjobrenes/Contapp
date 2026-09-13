<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\LedgerExporter;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerController extends Controller
{
    public function show(Request $request, CurrentCompany $currentCompany, LedgerService $service, string $dimension, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $ledger = $service->build($company, $dimension, $id, $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->json($ledger);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        LedgerService $service,
        LedgerExporter $exporter,
        string $dimension,
        int $id
    ): StreamedResponse {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $ledger = $service->build($company, $dimension, $id, $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $ledger),
            "mayor-{$ledger->ownerCode}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
