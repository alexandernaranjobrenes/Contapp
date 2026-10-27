<?php

namespace App\Http\Controllers;

use App\Domains\Repository\Models\RepositoryDocument;
use App\Domains\Repository\Models\RepositoryFolder;
use App\Domains\Repository\Services\RepositoryPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El Repositorio de las cuentas (CLAUDE.md secc. 39): los documentos que el
 * backoffice activó para verse, agrupados en sus carpetas, como PDF.
 * Cualquier cuenta, con cualquier rol: no es un permiso de la compañía
 * activa.
 */
class RepositoryController extends Controller
{
    public function __construct(private readonly RepositoryPdf $pdf) {}

    public function index(Request $request): Response
    {
        $folders = RepositoryFolder::query()
            ->whereHas('documents', fn ($query) => $query->visible())
            ->with(['documents' => fn ($query) => $query->visible()->orderBy('title')])
            ->orderBy('name')
            ->get()
            ->map(fn (RepositoryFolder $folder) => [
                'id' => $folder->id,
                'name' => $folder->name,
                'description' => $folder->description,
                'documents' => $folder->documents->map(fn (RepositoryDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'description' => $document->description,
                    'updated_at' => ($document->file_updated_at ?? $document->updated_at)->toIso8601String(),
                ])->values(),
            ]);

        return Inertia::render('Repository/Index', [
            'folders' => $folders,
            // Desde un enlace de Conti: el documento que hay que señalar.
            'highlight' => $request->integer('document') ?: null,
        ]);
    }

    /** El PDF, para verlo en otra pestaña o, con ?download=1, descargarlo. */
    public function pdf(Request $request, RepositoryDocument $document): HttpResponse
    {
        abort_unless($document->public_enabled, 404, 'Ese documento ya no está disponible en la Biblioteca.');

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($this->pdf->output($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->filename($document).'"',
        ]);
    }
}
