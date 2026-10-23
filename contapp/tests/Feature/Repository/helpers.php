<?php

use App\Domains\Repository\Models\RepositoryDocument;
use App\Domains\Repository\Models\RepositoryFolder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Ayudas de las pruebas del Repositorio (CLAUDE.md secc. 39). Las incluyen
| las pruebas de esta carpeta y las de Conti que lo consultan. El disco
| «repositories» tiene que estar falso (Storage::fake) antes de usarlas.
*/

if (! function_exists('repositoryDocument')) {
    /** Un documento del Repositorio, con su archivo en el disco, en la carpeta $folder. */
    function repositoryDocument(array $attributes = [], string $markdown = "# Ley de prueba\n\nArtículo 1.- Texto de la norma.", string $folder = 'Leyes'): RepositoryDocument
    {
        $path = $attributes['file_path'] ?? 'documento-'.Str::lower(Str::random(6)).'.md';
        Storage::disk(RepositoryDocument::DISK)->put($path, $markdown);

        return RepositoryDocument::create([
            'folder_id' => RepositoryFolder::firstOrCreate(['name' => $folder])->id,
            'title' => 'Ley de prueba',
            'file_path' => $path,
            'original_name' => 'ley.md',
            'size' => strlen($markdown),
            'file_updated_at' => now(),
            ...$attributes,
        ]);
    }
}
