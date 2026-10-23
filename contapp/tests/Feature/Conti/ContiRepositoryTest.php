<?php

use App\Domains\Conti\Agent\ContiAgent;
use App\Domains\Conti\Agent\ContiRepository;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Repository/helpers.php';

/*
|--------------------------------------------------------------------------
| Conti consulta el Repositorio
|--------------------------------------------------------------------------
|
| Solo los documentos que el backoffice activó para Conti. El modelo ve la
| lista en el mensaje de sistema y pide los pasajes con «repositorio», que
| dicen de qué documento, sección y artículo salen (CLAUDE.md secc. 39).
|
*/

beforeEach(fn () => Storage::fake('repositories'));

const IVA_LAW = <<<'MD'
# Ley del IVA

## Capítulo II. Hecho generador

Artículo 2.- El hecho generador del impuesto ocurre en la venta de bienes y en la prestación de servicios.

## Capítulo III. Exenciones

Artículo 8.- Están exentos los servicios de salud privados prestados por centros autorizados.
MD;

it('sin documentos activados para Conti, la herramienta no se ofrece y la lista no va en el mensaje', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    repositoryDocument(['public_enabled' => true]);

    $tools = contiTools($user, $company);

    expect(collect($tools->definitions())->pluck('function.name'))->not->toContain('repositorio')
        ->and(app(ContiAgent::class)->systemPrompt(null))->not->toContain('Documentos del repositorio');
});

it('con documentos activados, ofrece la herramienta y la lista va en el mensaje de sistema', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    repositoryDocument(['title' => 'Ley del IVA', 'conti_enabled' => true], folder: 'Leyes');
    repositoryDocument(['title' => 'Borrador', 'public_enabled' => true], folder: 'Leyes');

    $tools = contiTools($user, $company);
    $prompt = app(ContiAgent::class)->systemPrompt(null);

    expect(collect($tools->definitions())->pluck('function.name'))->toContain('repositorio')
        ->and($prompt)->toContain("## Documentos del repositorio (herramienta «repositorio»)\n- Leyes › Ley del IVA")
        ->not->toContain('Borrador');
});

it('busca los pasajes por tema, con documento, sección y artículo; el enlace solo si las cuentas lo ven', function () {
    ['user' => $user, 'company' => $company] = contiUser();
    $law = repositoryDocument(['title' => 'Ley del IVA', 'conti_enabled' => true, 'public_enabled' => true], IVA_LAW, 'Leyes');
    repositoryDocument(['title' => 'Reglamento interno', 'conti_enabled' => true], "# Reglamento\n\nArtículo 1.- Las exenciones se solicitan por escrito.", 'Reglamentos');
    repositoryDocument(['title' => 'Sin activar'], "# Exenciones\n\nExenciones y más exenciones.", 'Reglamentos');

    $tools = contiTools($user, $company);
    $result = $tools->run('repositorio', ['tema' => 'exenciones']);

    expect($result['pasajes'][0])->toBe([
        'documento' => 'Ley del IVA',
        'carpeta' => 'Leyes',
        'seccion' => 'Ley del IVA › Capítulo III. Exenciones',
        'articulo' => 'Artículo 8',
        'texto' => 'Artículo 8.- Están exentos los servicios de salud privados prestados por centros autorizados.',
        'enlace' => '/repository?document='.$law->id,
    ]);

    $documents = collect($result['pasajes'])->pluck('documento');
    expect($documents)->toContain('Reglamento interno')->not->toContain('Sin activar')
        ->and(collect($result['pasajes'])->firstWhere('documento', 'Reglamento interno'))->not->toHaveKey('enlace');

    // Solo en un documento, por parte de su título.
    $only = $tools->run('repositorio', ['tema' => 'exenciones', 'documento' => 'reglamento']);
    expect(collect($only['pasajes'])->pluck('documento')->unique()->all())->toBe(['Reglamento interno']);

    expect($tools->run('repositorio', ['tema' => 'exenciones', 'documento' => 'código de comercio'])['codigo'])->toBe(404)
        ->and($tools->run('repositorio', ['tema' => 'de la'])['codigo'])->toBe(422)
        ->and($tools->run('repositorio', ['tema' => 'aguinaldo'])['resultado'])->toContain('No encontré ese tema');
});

it('parte un documento largo en pasajes de hasta unos 2500 caracteres, cada uno con su primer artículo', function () {
    $articles = collect(range(1, 30))
        ->map(fn (int $i) => "Artículo {$i}.- ".str_repeat('texto de la norma ', 20))
        ->implode("\n\n");

    $chunks = app(ContiRepository::class)->chunks("# Código de Normas\n\n".$articles);

    expect(count($chunks))->toBeGreaterThan(3)
        ->and(collect($chunks)->every(fn (array $c) => mb_strlen($c['text']) <= 2500))->toBeTrue()
        ->and(collect($chunks)->every(fn (array $c) => $c['path'] === 'Código de Normas'))->toBeTrue()
        ->and($chunks[0]['article'])->toBe('Artículo 1')
        ->and($chunks[1]['article'])->toMatch('/^Artículo \d+$/');
});
