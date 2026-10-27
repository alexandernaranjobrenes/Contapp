<?php

use App\Domains\Repository\Models\RepositoryDocument;
use App\Domains\Repository\Models\RepositoryFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El Repositorio desde el backoffice
|--------------------------------------------------------------------------
|
| El Propietario sube documentos en Markdown a public/repositories, los
| agrupa en carpetas y decide de cada uno si Conti lo consulta y si las
| cuentas lo ven como PDF (CLAUDE.md secc. 39).
|
*/

beforeEach(function () {
    Storage::fake('repositories');
    Storage::fake('local');
});

it('sube un documento: el archivo queda en el disco, con el título de su primer encabezado y en la carpeta nueva', function () {
    $propietario = loginAsPropietario();
    $markdown = "# Ley del Impuesto al Valor Agregado\n\n## Capítulo I\n\nArtículo 1.- Objeto del impuesto.";

    $this->post(route('backoffice.repository.documents.store'), [
        'title' => '',
        'new_folder' => 'Leyes',
        'file' => UploadedFile::fake()->createWithContent('ley-iva.md', $markdown),
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $document = RepositoryDocument::sole();

    expect($document->title)->toBe('Ley del Impuesto al Valor Agregado')
        ->and($document->folder->name)->toBe('Leyes')
        ->and($document->conti_enabled)->toBeFalse()
        ->and($document->public_enabled)->toBeFalse()
        ->and($document->original_name)->toBe('ley-iva.md')
        ->and($document->size)->toBe(strlen($markdown))
        ->and($document->created_by)->toBe($propietario->id)
        ->and($document->file_updated_at)->not->toBeNull()
        ->and($document->file_path)->toMatch('/^ley-del-impuesto-al-valor-agregado-[a-z0-9]{6}\.md$/');

    expect(Storage::disk('repositories')->get($document->file_path))->toBe($markdown);
});

it('sube a una carpeta que ya existe, con título propio y ya activado', function () {
    loginAsPropietario();
    $folder = RepositoryFolder::create(['name' => 'Resoluciones']);

    $this->post(route('backoffice.repository.documents.store'), [
        'title' => 'Resolución DGT-R-001-2026',
        'description' => 'Sobre el comprobante electrónico 4.4.',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('resolucion.md', "Texto de la resolución.\n"),
        'conti_enabled' => 1,
        'public_enabled' => 1,
    ])->assertSessionHasNoErrors();

    $document = RepositoryDocument::sole();

    expect($document->title)->toBe('Resolución DGT-R-001-2026')
        ->and($document->description)->toBe('Sobre el comprobante electrónico 4.4.')
        ->and($document->folder_id)->toBe($folder->id)
        ->and($document->conti_enabled)->toBeTrue()
        ->and($document->public_enabled)->toBeTrue();
});

it('un archivo guardado en Windows-1252 llega en UTF-8, y sin la marca BOM', function () {
    loginAsPropietario();
    $folder = RepositoryFolder::create(['name' => 'Leyes']);

    $this->post(route('backoffice.repository.documents.store'), [
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('codigo.md', mb_convert_encoding("# Código de Normas\n\nTributación y exención.", 'Windows-1252', 'UTF-8')),
    ])->assertSessionHasNoErrors();

    $this->post(route('backoffice.repository.documents.store'), [
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('bom.md', "\xEF\xBB\xBF# Reglamento"),
    ])->assertSessionHasNoErrors();

    [$code, $bom] = RepositoryDocument::orderBy('id')->get()->all();

    expect(Storage::disk('repositories')->get($code->file_path))->toBe("# Código de Normas\n\nTributación y exención.")
        ->and($code->title)->toBe('Código de Normas')
        ->and(Storage::disk('repositories')->get($bom->file_path))->toBe('# Reglamento')
        ->and($bom->title)->toBe('Reglamento');
});

it('rechaza lo que no es un Markdown: otra extensión, un binario, un archivo vacío o ningún archivo', function () {
    loginAsPropietario();
    $folder = RepositoryFolder::create(['name' => 'Leyes']);
    $store = fn (array $data) => $this->post(route('backoffice.repository.documents.store'), ['folder_id' => $folder->id, ...$data]);

    $store(['file' => UploadedFile::fake()->createWithContent('ley.pdf', 'texto')])->assertSessionHasErrors(['file' => 'El archivo tiene que ser un Markdown (.md).']);
    $store(['file' => UploadedFile::fake()->createWithContent('ley.md', "%PDF-1.7\0\0binario")])->assertSessionHasErrors(['file' => 'El archivo no es texto: tiene que ser un Markdown (.md).']);
    $store(['file' => UploadedFile::fake()->createWithContent('ley.md', "  \n ")])->assertSessionHasErrors(['file' => 'El archivo está vacío.']);
    $store([])->assertSessionHasErrors(['file' => 'Elegí el archivo .md del documento.']);

    expect(RepositoryDocument::count())->toBe(0)
        ->and(Storage::disk('repositories')->allFiles())->toBe([]);
});

it('sin carpeta, o con una nueva que ya existe, lo dice junto al campo', function () {
    loginAsPropietario();
    RepositoryFolder::create(['name' => 'Leyes']);
    $file = fn () => UploadedFile::fake()->createWithContent('ley.md', '# Ley');

    $this->post(route('backoffice.repository.documents.store'), ['file' => $file()])
        ->assertSessionHasErrors(['folder_id' => 'Elegí la carpeta, o escribí el nombre de una nueva.']);

    $this->post(route('backoffice.repository.documents.store'), ['new_folder' => 'Leyes', 'file' => $file()])
        ->assertSessionHasErrors(['new_folder' => 'Ya hay una carpeta con ese nombre: elegila de la lista.']);

    expect(RepositoryDocument::count())->toBe(0);
});

it('edita sin tocar el archivo, y al reemplazarlo pisa el mismo archivo y cambia la fecha', function () {
    loginAsPropietario();
    $document = repositoryDocument(['file_updated_at' => now()->subWeek()], "# Versión 1\n\nTexto viejo.");
    $other = RepositoryFolder::create(['name' => 'Reglamentos']);
    $path = $document->file_path;
    // Como quedó en la base (sin microsegundos).
    $uploadedAt = $document->fresh()->file_updated_at;

    $this->post(route('backoffice.repository.documents.update', $document), [
        '_method' => 'put',
        'title' => 'Reglamento renombrado',
        'folder_id' => $other->id,
    ])->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->title)->toBe('Reglamento renombrado')
        ->and($document->folder_id)->toBe($other->id)
        ->and($document->file_updated_at->equalTo($uploadedAt))->toBeTrue()
        ->and(Storage::disk('repositories')->get($path))->toBe("# Versión 1\n\nTexto viejo.");

    $this->post(route('backoffice.repository.documents.update', $document), [
        '_method' => 'put',
        'title' => 'Reglamento renombrado',
        'folder_id' => $other->id,
        'file' => UploadedFile::fake()->createWithContent('version-2.md', "# Versión 2\n\nTexto nuevo."),
    ])->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->file_path)->toBe($path)
        ->and($document->original_name)->toBe('version-2.md')
        ->and($document->file_updated_at->greaterThan($uploadedAt))->toBeTrue()
        ->and(Storage::disk('repositories')->get($path))->toBe("# Versión 2\n\nTexto nuevo.")
        ->and(Storage::disk('repositories')->allFiles())->toBe([$path]);
});

it('activa y desactiva cada interruptor por separado', function () {
    loginAsPropietario();
    $document = repositoryDocument();
    $set = fn (array $flags) => $this->put(route('backoffice.repository.documents.availability', $document), $flags)->assertSessionHasNoErrors();

    $set(['conti_enabled' => 1]);
    expect($document->fresh()->only('conti_enabled', 'public_enabled'))->toBe(['conti_enabled' => true, 'public_enabled' => false]);

    $set(['public_enabled' => 1]);
    expect($document->fresh()->only('conti_enabled', 'public_enabled'))->toBe(['conti_enabled' => true, 'public_enabled' => true]);

    $set(['conti_enabled' => 0])->assertSessionHas('success', '«Ley de prueba»: Conti no lo consulta y las cuentas lo ven en la Biblioteca.');
    expect($document->fresh()->only('conti_enabled', 'public_enabled'))->toBe(['conti_enabled' => false, 'public_enabled' => true]);
});

it('ve el PDF de cualquier documento, lo guarda y lo reusa, y descarga el Markdown', function () {
    loginAsPropietario();
    $document = repositoryDocument(['title' => 'Ley del IVA'], "# Ley del IVA\n\n## Capítulo I\n\nArtículo 1.- Objeto.\n\n| Tarifa | Bienes |\n|---|---|\n| 13 % | General |");

    $response = $this->get(route('backoffice.repository.documents.pdf', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="ley-del-iva.pdf"')
        ->and(Storage::disk('local')->files('repository-pdfs'))->toHaveCount(1);

    $cached = Storage::disk('local')->files('repository-pdfs')[0];
    $this->get(route('backoffice.repository.documents.pdf', $document))->assertOk();
    expect(Storage::disk('local')->files('repository-pdfs'))->toBe([$cached]);

    // Cambió el título: el PDF se rehace, y el viejo no queda.
    $document->update(['title' => 'Ley del Impuesto al Valor Agregado']);
    $this->get(route('backoffice.repository.documents.pdf', $document))->assertOk();
    expect(Storage::disk('local')->files('repository-pdfs'))->toHaveCount(1)->not->toContain($cached);

    $this->get(route('backoffice.repository.documents.markdown', $document))
        ->assertOk()
        ->assertDownload('ley.md');
});

it('elimina el documento con su archivo y su PDF guardado', function () {
    loginAsPropietario();
    $document = repositoryDocument();
    $this->get(route('backoffice.repository.documents.pdf', $document))->assertOk();

    $this->delete(route('backoffice.repository.documents.destroy', $document))->assertSessionHas('success');

    expect(RepositoryDocument::count())->toBe(0)
        ->and(Storage::disk('repositories')->allFiles())->toBe([])
        ->and(Storage::disk('local')->files('repository-pdfs'))->toBe([]);
});

it('las carpetas: se crean, se renombran sin repetir nombre, y una con documentos no se elimina', function () {
    loginAsPropietario();

    $this->post(route('backoffice.repository.folders.store'), ['name' => 'Leyes', 'description' => 'Leyes de la República'])->assertSessionHasNoErrors();
    $this->post(route('backoffice.repository.folders.store'), ['name' => 'Reglamentos'])->assertSessionHasNoErrors();
    [$laws, $rules] = RepositoryFolder::orderBy('id')->get()->all();

    $this->put(route('backoffice.repository.folders.update', $rules), ['name' => 'Leyes'])
        ->assertSessionHasErrors(['name' => 'Ya hay una carpeta con ese nombre.']);
    $this->put(route('backoffice.repository.folders.update', $rules), ['name' => 'Reglamentos ejecutivos'])->assertSessionHasNoErrors();
    expect($rules->fresh()->name)->toBe('Reglamentos ejecutivos');

    repositoryDocument(folder: 'Leyes');
    $this->delete(route('backoffice.repository.folders.destroy', $laws))
        ->assertSessionHasErrors(['folder' => 'La carpeta tiene un documento: movelo a otra carpeta o eliminalo primero.']);
    expect($laws->fresh())->not->toBeNull();

    $this->delete(route('backoffice.repository.folders.destroy', $rules))->assertSessionHas('success');
    expect($rules->fresh())->toBeNull();
});

it('la pantalla lista las carpetas con su cantidad y los documentos con sus interruptores', function () {
    loginAsPropietario();
    repositoryDocument(['title' => 'Ley del IVA', 'conti_enabled' => true], folder: 'Leyes');
    RepositoryFolder::create(['name' => 'Artículos']);

    $this->get(route('backoffice.repository.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Backoffice/Repository/Index')
        ->where('folders.0.name', 'Artículos')
        ->where('folders.0.documents_count', 0)
        ->where('folders.1.name', 'Leyes')
        ->where('folders.1.documents_count', 1)
        ->where('documents.0.title', 'Ley del IVA')
        ->where('documents.0.folder', 'Leyes')
        ->where('documents.0.conti_enabled', true)
        ->where('documents.0.public_enabled', false)
        ->where('maxMegabytes', 5));
});

it('una cuenta de compañía no entra al Repositorio del backoffice', function () {
    logInAsCompanyUser();

    $this->get(route('backoffice.repository.index'))->assertRedirect(route('backoffice.login'));
    $this->post(route('backoffice.repository.folders.store'), ['name' => 'Leyes'])->assertRedirect(route('backoffice.login'));

    expect(RepositoryFolder::count())->toBe(0);
});
