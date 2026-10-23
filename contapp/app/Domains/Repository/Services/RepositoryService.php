<?php

namespace App\Domains\Repository\Services;

use App\Domains\Licensing\Models\Propietario;
use App\Domains\Repository\Exceptions\RepositoryException;
use App\Domains\Repository\Models\RepositoryDocument;
use App\Domains\Repository\Models\RepositoryFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El Repositorio desde el backoffice (CLAUDE.md secc. 39): carpetas y
 * documentos en Markdown, con sus dos interruptores (Conti y las cuentas).
 *
 * El archivo va al disco «repositories» como {título}-{azar}.md: el nombre
 * se reconoce al mirar la carpeta, y el azar evita choques entre dos
 * documentos con el mismo título. Al reemplazarlo se pisa el mismo archivo.
 */
class RepositoryService
{
    public function __construct(private readonly RepositoryPdf $pdf) {}

    /** @param  array{name: string, description: ?string}  $data */
    public function saveFolder(RepositoryFolder $folder, array $data, Propietario $by): RepositoryFolder
    {
        $folder->fill([
            'name' => trim($data['name']),
            'description' => $this->text($data['description'] ?? null),
            'updated_by' => $by->id,
        ]);

        if (! $folder->exists) {
            $folder->created_by = $by->id;
        }

        $folder->save();

        // El nombre de la carpeta va en el encabezado del PDF.
        if ($folder->wasChanged('name')) {
            $folder->documents()->each(fn (RepositoryDocument $document) => $this->pdf->forget($document));
        }

        return $folder;
    }

    /** @throws RepositoryException */
    public function deleteFolder(RepositoryFolder $folder): void
    {
        $count = $folder->documents()->count();

        if ($count > 0) {
            throw new RepositoryException(
                $count === 1
                    ? 'La carpeta tiene un documento: movelo a otra carpeta o eliminalo primero.'
                    : "La carpeta tiene {$count} documentos: movelos a otra carpeta o eliminalos primero.",
                'folder',
            );
        }

        $folder->delete();
    }

    /**
     * Crea el documento (con $file) o guarda los cambios de uno que ya existe;
     * con $file en uno que existe, le reemplaza el archivo.
     *
     * @param  array{folder_id: int, title: ?string, description: ?string, conti_enabled?: bool, public_enabled?: bool}  $data
     *
     * @throws RepositoryException
     */
    public function saveDocument(RepositoryDocument $document, array $data, Propietario $by, ?UploadedFile $file = null): RepositoryDocument
    {
        if (! $document->exists && $file === null) {
            throw new RepositoryException('Elegí el archivo .md del documento.');
        }

        $markdown = $file !== null ? $this->readUpload($file) : null;

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            $title = $markdown !== null ? $this->titleFrom($markdown, $file) : $document->title;
        }

        $title = Str::limit($title, RepositoryDocument::MAX_TITLE, '');
        $creating = ! $document->exists;

        try {
            $this->persist($document, $data, $by, $file, $markdown, $title);
        } catch (\Throwable $e) {
            // Un documento nuevo que no llegó a guardarse no deja su archivo.
            if ($creating && $document->file_path) {
                Storage::disk(RepositoryDocument::DISK)->delete($document->file_path);
            }

            throw $e;
        }

        $this->pdf->forget($document);

        return $document;
    }

    private function persist(RepositoryDocument $document, array $data, Propietario $by, ?UploadedFile $file, ?string $markdown, string $title): void
    {
        DB::transaction(function () use ($document, $data, $by, $file, $markdown, $title) {
            $document->fill([
                'folder_id' => $data['folder_id'],
                'title' => $title,
                'description' => $this->text($data['description'] ?? null),
                'updated_by' => $by->id,
            ]);

            foreach (['conti_enabled', 'public_enabled'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $document->{$flag} = (bool) $data[$flag];
                }
            }

            if (! $document->exists) {
                $document->created_by = $by->id;
                $document->file_path = (Str::limit(Str::slug($title), 80, '') ?: 'documento').'-'.Str::lower(Str::random(6)).'.md';
            }

            if ($markdown !== null) {
                if (! Storage::disk(RepositoryDocument::DISK)->put($document->file_path, $markdown)) {
                    throw new RepositoryException('No se pudo guardar el archivo. Probá de nuevo en un momento.');
                }

                $document->original_name = Str::limit($file->getClientOriginalName(), 250, '');
                $document->size = strlen($markdown);
                $document->file_updated_at = now();
            }

            $document->save();
        });
    }

    /** Los dos interruptores: que Conti lo consulte, que las cuentas lo vean. */
    public function setAvailability(RepositoryDocument $document, array $flags, Propietario $by): RepositoryDocument
    {
        foreach (['conti_enabled', 'public_enabled'] as $flag) {
            if (array_key_exists($flag, $flags)) {
                $document->{$flag} = (bool) $flags[$flag];
            }
        }

        $document->updated_by = $by->id;
        $document->save();

        return $document;
    }

    public function deleteDocument(RepositoryDocument $document): void
    {
        $path = $document->file_path;

        $document->delete();

        Storage::disk(RepositoryDocument::DISK)->delete($path);
        $this->pdf->forget($document);
    }

    /**
     * El Markdown del archivo subido, en UTF-8 y sin la marca de orden de
     * bytes. Un archivo guardado en Windows con la codificación de siempre
     * (Windows-1252) se convierte: las tildes no se pierden.
     *
     * @throws RepositoryException
     */
    public function readUpload(UploadedFile $file): string
    {
        $contents = (string) $file->get();

        if (trim($contents) === '') {
            throw new RepositoryException('El archivo está vacío.');
        }

        // Un archivo de texto no trae bytes nulos: esto es otra cosa (un PDF,
        // un Word, una imagen con la extensión cambiada).
        if (str_contains($contents, "\0")) {
            throw new RepositoryException('El archivo no es texto: tiene que ser un Markdown (.md).');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /** Sin título escrito: el primer encabezado del documento, o el nombre del archivo. */
    private function titleFrom(string $markdown, UploadedFile $file): string
    {
        if (preg_match('/^\s{0,3}#{1,2}\s+(.+?)\s*#*\s*$/m', $markdown, $m)) {
            return trim(strip_tags(str_replace(['**', '__', '`'], '', $m[1])));
        }

        return Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->replace(['-', '_'], ' ')
            ->squish()
            ->ucfirst()
            ->toString();
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
