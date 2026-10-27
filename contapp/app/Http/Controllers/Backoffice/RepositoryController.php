<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Repository\Exceptions\RepositoryException;
use App\Domains\Repository\Models\RepositoryDocument;
use App\Domains\Repository\Models\RepositoryFolder;
use App\Domains\Repository\Services\RepositoryPdf;
use App\Domains\Repository\Services\RepositoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El Repositorio desde el backoffice (CLAUDE.md secc. 39): subir documentos en
 * Markdown, agruparlos en carpetas y decidir, de cada uno, si Conti lo
 * consulta y si las cuentas lo ven como PDF. Las carpetas se manejan desde la
 * misma pantalla (RepositoryFolderController).
 */
class RepositoryController extends Controller
{
    public function __construct(
        private readonly RepositoryService $repository,
        private readonly RepositoryPdf $pdf,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Backoffice/Repository/Index', [
            'folders' => RepositoryFolder::query()
                ->withCount('documents')
                ->orderBy('name')
                ->get()
                ->map(fn (RepositoryFolder $folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'description' => $folder->description,
                    'documents_count' => $folder->documents_count,
                ]),
            'documents' => RepositoryDocument::query()
                ->with(['folder:id,name', 'creator:id,name', 'updater:id,name'])
                ->orderBy('title')
                ->get()
                ->map(fn (RepositoryDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'description' => $document->description,
                    'folder_id' => $document->folder_id,
                    'folder' => $document->folder?->name,
                    'original_name' => $document->original_name,
                    'file_path' => $document->file_path,
                    'size' => $document->size,
                    'conti_enabled' => $document->conti_enabled,
                    'public_enabled' => $document->public_enabled,
                    'file_updated_at' => $document->file_updated_at?->toIso8601String(),
                    'created_by' => $document->creator?->name,
                    'updated_by' => $document->updater?->name,
                    'created_at' => $document->created_at->toIso8601String(),
                    'updated_at' => $document->updated_at->toIso8601String(),
                ]),
            'maxMegabytes' => RepositoryDocument::MAX_KILOBYTES / 1024,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new RepositoryDocument, 'El documento quedó en la Biblioteca.');
    }

    public function update(Request $request, RepositoryDocument $document): RedirectResponse
    {
        return $this->save($request, $document, 'Los cambios del documento quedaron guardados.');
    }

    /** Los interruptores: que Conti lo consulte, que las cuentas lo vean. */
    public function availability(Request $request, RepositoryDocument $document): RedirectResponse
    {
        $validated = $request->validate([
            'conti_enabled' => ['sometimes', 'boolean'],
            'public_enabled' => ['sometimes', 'boolean'],
        ]);

        $this->repository->setAvailability($document, $validated, $request->user('propietario'));

        return back()->with('success', $this->availabilityMessage($document));
    }

    public function destroy(RepositoryDocument $document): RedirectResponse
    {
        $this->repository->deleteDocument($document);

        return back()->with('success', 'El documento se eliminó de la Biblioteca.');
    }

    /** La vista previa del PDF, esté o no activo para las cuentas. */
    public function pdf(RepositoryDocument $document): HttpResponse
    {
        return response($this->pdf->output($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($document).'"',
        ]);
    }

    /** El Markdown tal como está guardado. */
    public function markdown(RepositoryDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk(RepositoryDocument::DISK)->exists($document->file_path), 404, 'El archivo de este documento ya no está en el servidor.');

        return Storage::disk(RepositoryDocument::DISK)->download($document->file_path, $document->original_name, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function save(Request $request, RepositoryDocument $document, string $message): RedirectResponse
    {
        $creating = ! $document->exists;

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:'.RepositoryDocument::MAX_TITLE],
            'description' => ['nullable', 'string', 'max:'.RepositoryDocument::MAX_DESCRIPTION],
            'folder_id' => ['nullable', 'required_without:new_folder', 'integer', Rule::exists('repository_folders', 'id')],
            'new_folder' => ['nullable', 'required_without:folder_id', 'string', 'max:'.RepositoryFolder::MAX_NAME, Rule::unique('repository_folders', 'name')],
            'file' => [$creating ? 'required' : 'nullable', 'file', 'max:'.RepositoryDocument::MAX_KILOBYTES, 'extensions:md,markdown'],
            'conti_enabled' => ['sometimes', 'boolean'],
            'public_enabled' => ['sometimes', 'boolean'],
        ], [
            'file.required' => 'Elegí el archivo .md del documento.',
            'file.extensions' => 'El archivo tiene que ser un Markdown (.md).',
            'file.max' => 'El archivo puede pesar hasta '.(RepositoryDocument::MAX_KILOBYTES / 1024).' MB.',
            'file.uploaded' => 'El archivo no se pudo subir. Probá de nuevo.',
            'folder_id.required_without' => 'Elegí la carpeta, o escribí el nombre de una nueva.',
            'new_folder.required_without' => 'Escribí el nombre de la carpeta nueva.',
            'new_folder.unique' => 'Ya hay una carpeta con ese nombre: elegila de la lista.',
        ], [
            'title' => 'título',
            'description' => 'descripción',
            'folder_id' => 'carpeta',
            'new_folder' => 'carpeta nueva',
            'file' => 'archivo',
        ]);

        $by = $request->user('propietario');

        try {
            DB::transaction(function () use ($validated, $document, $by, $request) {
                $folderId = $validated['folder_id'] ?? null;

                // «Nueva carpeta…» en el mismo formulario: se crea con el
                // documento, y si el documento no se guarda, tampoco ella.
                if (filled($validated['new_folder'] ?? null)) {
                    $folderId = $this->repository->saveFolder(new RepositoryFolder, ['name' => $validated['new_folder'], 'description' => null], $by)->id;
                }

                $this->repository->saveDocument(
                    $document,
                    [
                        'folder_id' => (int) $folderId,
                        'title' => $validated['title'] ?? null,
                        'description' => $validated['description'] ?? null,
                        ...array_intersect_key($validated, array_flip(['conti_enabled', 'public_enabled'])),
                    ],
                    $by,
                    $request->file('file'),
                );
            });
        } catch (RepositoryException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }

    private function availabilityMessage(RepositoryDocument $document): string
    {
        $conti = $document->conti_enabled ? 'Conti lo consulta' : 'Conti no lo consulta';
        $public = $document->public_enabled ? 'las cuentas lo ven en la Biblioteca' : 'las cuentas no lo ven';

        return "«{$document->title}»: {$conti} y {$public}.";
    }
}
