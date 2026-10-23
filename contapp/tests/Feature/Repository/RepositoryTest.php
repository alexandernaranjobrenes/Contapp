<?php

use App\Domains\Repository\Services\RepositoryPdf;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El Repositorio de las cuentas
|--------------------------------------------------------------------------
|
| Cualquier cuenta ve, agrupados en sus carpetas, los documentos que el
| backoffice activó para verse, y los abre como PDF. Lo que no está activo
| no existe para ellas (CLAUDE.md secc. 39).
|
*/

beforeEach(function () {
    Storage::fake('repositories');
    Storage::fake('local');
});

it('una cuenta ve solo lo visible, agrupado por carpeta; una carpeta sin nada visible no aparece', function () {
    logInAsCompanyUser();
    $visible = repositoryDocument(['title' => 'Ley del IVA', 'description' => 'Ley 9635.', 'public_enabled' => true], folder: 'Leyes');
    repositoryDocument(['title' => 'Borrador', 'conti_enabled' => true], folder: 'Leyes');
    repositoryDocument(['title' => 'Solo para Conti', 'conti_enabled' => true], folder: 'Internos');

    $this->get(route('repository.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Repository/Index')
        ->has('folders', 1)
        ->where('folders.0.name', 'Leyes')
        ->has('folders.0.documents', 1)
        ->where('folders.0.documents.0.id', $visible->id)
        ->where('folders.0.documents.0.title', 'Ley del IVA')
        ->where('folders.0.documents.0.description', 'Ley 9635.')
        ->where('highlight', null));
});

it('un enlace de Conti llega con el documento que hay que señalar', function () {
    logInAsCompanyUser();
    $document = repositoryDocument(['public_enabled' => true]);

    $this->get(route('repository.index', ['document' => $document->id]))
        ->assertInertia(fn ($page) => $page->where('highlight', $document->id));
});

it('abre como PDF un documento visible, o lo descarga; uno oculto no existe para las cuentas', function () {
    logInAsCompanyUser();
    $visible = repositoryDocument(['title' => 'Ley del IVA', 'public_enabled' => true]);
    $hidden = repositoryDocument(['title' => 'Borrador', 'conti_enabled' => true]);

    $response = $this->get(route('repository.pdf', $visible))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="ley-del-iva.pdf"');

    expect($this->get(route('repository.pdf', ['document' => $visible->id, 'download' => 1]))->headers->get('Content-Disposition'))
        ->toBe('attachment; filename="ley-del-iva.pdf"');

    $this->get(route('repository.pdf', $hidden))->assertNotFound();
});

it('sin sesión, el Repositorio pide iniciar sesión', function () {
    $document = repositoryDocument(['public_enabled' => true]);

    $this->get(route('repository.index'))->assertRedirect(route('login'));
    $this->get(route('repository.pdf', $document))->assertRedirect(route('login'));
});

it('el PDF toma el formato del Markdown, nunca HTML crudo ni imágenes', function () {
    $html = app(RepositoryPdf::class)->html(implode("\n\n", [
        '# Título',
        '<script>alert(1)</script>',
        '![Escudo nacional](http://sitio-externo/escudo.png)',
        '![](file:///etc/passwd)',
        '[un enlace](javascript:alert(1))',
        "| Tarifa | Bienes |\n|---|---|\n| 13 % | General |",
    ]));

    expect($html)->toContain('<h1>Título</h1>')
        ->toContain('<table>')
        ->toContain('<em>[Escudo nacional]</em>')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('javascript:');
});
