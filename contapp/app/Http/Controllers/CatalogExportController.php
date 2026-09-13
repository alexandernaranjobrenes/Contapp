<?php

namespace App\Http\Controllers;

use App\Domains\Reporting\Services\CatalogExporter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogExportController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Reports/CatalogExport', [
            'catalogs' => CatalogExporter::CATALOGS,
        ]);
    }

    public function export(Request $request, CatalogExporter $exporter): StreamedResponse
    {
        $validated = $this->validateFilters($request);

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $validated['catalogs'], $validated['include_inactive']),
            'catalogos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'catalogs' => ['required', 'array', 'min:1'],
            'catalogs.*' => ['string', 'in:'.implode(',', array_keys(CatalogExporter::CATALOGS))],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        return [
            'catalogs' => $validated['catalogs'],
            'include_inactive' => $request->boolean('include_inactive', false),
        ];
    }
}
