<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\DocumentType;
use App\Domains\Reporting\Services\DocumentTypeRegisterExporter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentTypeRegisterController extends Controller
{
    private const VALID_STATUSES = ['draft', 'posted', 'voided'];

    public function index(): InertiaResponse
    {
        return Inertia::render('Reports/DocumentTypeRegister', [
            'documentTypes' => DocumentType::generatesJournalActive()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function export(Request $request, DocumentTypeRegisterExporter $exporter): StreamedResponse
    {
        $validated = $this->validateFilters($request);

        // document_type_id ausente = "Todos los tipos de documento" (sin
        // filtrar). DocumentType::findOrFail ya respeta el CompanyScope
        // ambiental — un id de otra compañía da 404, nunca filtra datos ajenos.
        $documentType = $validated['document_type_id']
            ? DocumentType::findOrFail($validated['document_type_id'])
            : null;

        $fileName = $documentType ? "registro-{$documentType->code}.xlsx" : 'registro-todos-los-documentos.xlsx';

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $documentType, $validated['from'], $validated['to'], $validated['statuses']),
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'document_type_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'statuses' => ['nullable', 'array', 'min:1'],
            'statuses.*' => ['string', 'in:'.implode(',', self::VALID_STATUSES)],
        ]);

        return [
            'document_type_id' => $validated['document_type_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'statuses' => $validated['statuses'] ?? ['posted'],
        ];
    }
}
