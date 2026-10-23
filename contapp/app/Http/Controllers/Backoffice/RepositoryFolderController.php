<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Repository\Exceptions\RepositoryException;
use App\Domains\Repository\Models\RepositoryFolder;
use App\Domains\Repository\Services\RepositoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las carpetas del Repositorio (CLAUDE.md secc. 39), desde la ventana
 * «Carpetas» de su pantalla del backoffice. Una con documentos no se elimina.
 */
class RepositoryFolderController extends Controller
{
    public function __construct(private readonly RepositoryService $repository) {}

    public function store(Request $request): RedirectResponse
    {
        $this->repository->saveFolder(new RepositoryFolder, $this->validated($request), $request->user('propietario'));

        return back()->with('success', 'La carpeta quedó creada.');
    }

    public function update(Request $request, RepositoryFolder $folder): RedirectResponse
    {
        $this->repository->saveFolder($folder, $this->validated($request, $folder), $request->user('propietario'));

        return back()->with('success', 'Los cambios de la carpeta quedaron guardados.');
    }

    public function destroy(RepositoryFolder $folder): RedirectResponse
    {
        try {
            $this->repository->deleteFolder($folder);
        } catch (RepositoryException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', 'La carpeta se eliminó.');
    }

    /** @return array{name: string, description: ?string} */
    private function validated(Request $request, ?RepositoryFolder $folder = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:'.RepositoryFolder::MAX_NAME, Rule::unique('repository_folders', 'name')->ignore($folder?->id)],
            'description' => ['nullable', 'string', 'max:'.RepositoryFolder::MAX_DESCRIPTION],
        ], [
            'name.unique' => 'Ya hay una carpeta con ese nombre.',
        ], [
            'name' => 'nombre',
            'description' => 'descripción',
        ]);
    }
}
