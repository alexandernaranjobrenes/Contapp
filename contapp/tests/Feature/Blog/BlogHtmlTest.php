<?php

use App\Domains\Blog\Support\BlogHtml;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El HTML de un artículo del blog
|--------------------------------------------------------------------------
|
| Un artículo se muestra a cualquiera: lo que llega del editor se limpia con
| una lista blanca (BlogHtml::clean). Lo que no está en la lista se cae; el
| texto queda como texto (CLAUDE.md secc. 41).
|
*/

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.media' => 's3']);
});

it('deja el formato de un artículo: títulos, listas, tablas, enlaces y citas', function () {
    $html = '<h2 class="text-center">Título</h2><p>Un <strong>texto</strong> con <em>cursiva</em>, <u>subrayado</u>, <s>tachado</s> y <mark>resaltado</mark>.</p>'
        .'<ul><li><p>Uno</p></li></ul><ol start="3"><li><p>Tres</p></li></ol><blockquote><p>Cita</p></blockquote><hr>'
        .'<table><tbody><tr><th colspan="1" rowspan="1"><p>A</p></th></tr><tr><td><p>1</p></td></tr></tbody></table>'
        .'<p><a href="https://pivoxcr.com/precios" target="_blank">Precios</a> y <a href="/blog">el blog</a></p>';

    $clean = BlogHtml::clean($html);

    expect($clean)
        ->toContain('<h2 class="text-center">Título</h2>')
        ->toContain('<strong>texto</strong>')
        ->toContain('<mark>resaltado</mark>')
        ->toContain('<ol start="3">')
        ->toContain('<blockquote><p>Cita</p></blockquote>')
        ->toContain('<th colspan="1" rowspan="1"><p>A</p></th>')
        ->toContain('<a href="https://pivoxcr.com/precios" target="_blank" rel="noopener noreferrer">Precios</a>')
        ->toContain('<a href="/blog">el blog</a>');
});

it('quita scripts, eventos, estilos y lo que no está en la lista', function () {
    $html = '<p onclick="robar()" style="color:red">Hola<script>alert(1)</script></p>'
        .'<img src="x" onerror="alert(1)"><iframe src="https://malo.test"></iframe><style>body{display:none}</style>'
        .'<div class="wrapper"><span style="font-family:Arial">texto de Word</span></div>'
        .'<p class="MsoNormal text-right evil">Alineado</p><svg><script>alert(1)</script></svg><!-- comentario -->';

    $clean = BlogHtml::clean($html);

    expect($clean)
        ->toBe('<p>Hola</p>texto de Word<p class="text-right">Alineado</p>')
        ->not->toContain('script')
        ->not->toContain('onerror')
        ->not->toContain('style');
});

it('no deja enlaces peligrosos aunque vengan disfrazados', function (string $href) {
    $clean = BlogHtml::clean('<p><a href="'.$href.'">clic</a></p>');

    expect($clean)->toBe('<p>clic</p>');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'con mayúsculas' => ['JaVaScRiPt:alert(1)'],
    'con tabulador' => ['java&#9;script:alert(1)'],
    'con salto de línea' => ['java&#10;script:alert(1)'],
    'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
    'vbscript' => ['vbscript:msgbox(1)'],
    'otro sitio sin esquema' => ['//malo.test'],
    'barra invertida' => ['/\\malo.test'],
]);

it('acepta correos, teléfonos, páginas del sitio y anclas', function (string $href) {
    expect(BlogHtml::clean('<p><a href="'.$href.'">x</a></p>'))->toBe('<p><a href="'.$href.'">x</a></p>');
})->with([
    'correo' => ['mailto:servicioalcliente@pivoxcr.com'],
    'teléfono' => ['tel:+50622223333'],
    'página del sitio' => ['/precios'],
    'ancla' => ['#iva'],
    'https' => ['https://www.hacienda.go.cr'],
]);

it('guarda las imágenes por su id y sin dirección, y solo las permitidas', function () {
    $html = '<figure class="image-medium"><img src="https://otro.test/x.jpg" data-image-id="7" alt="Factura"><figcaption>Pie</figcaption></figure>'
        .'<figure><img src="https://otro.test/y.jpg" data-image-id="8" alt="Otra"></figure>'
        .'<p><img src="https://otro.test/sin-id.jpg" alt="Copiada de otra página"></p>';

    $clean = BlogHtml::clean($html, [7]);

    expect($clean)
        ->toBe('<figure class="image-medium"><img data-image-id="7" alt="Factura"><figcaption>Pie</figcaption></figure><p></p>')
        ->and(BlogHtml::imageIds($html))->toBe([7, 8]);
});

it('al mostrarlo, le pone a cada imagen su dirección y sus medidas, e ids a los títulos', function () {
    $image = blogImage();
    $html = '<h2>Primer paso</h2><p>Texto</p><h2>Primer paso</h2><h3>Detalle</h3>'
        .'<figure><img data-image-id="'.$image->id.'" alt="Captura"></figure><figure><img data-image-id="999" alt="Ya no está"></figure>'
        .'<table><tbody><tr><td>1</td></tr></tbody></table>';

    ['html' => $rendered, 'toc' => $toc] = BlogHtml::render($html);

    expect($rendered)
        ->toContain('<h2 id="primer-paso">Primer paso</h2>')
        ->toContain('<h2 id="primer-paso-2">Primer paso</h2>')
        ->toContain('src="'.Storage::disk('s3')->url($image->path).'"')
        ->toContain('width="1200" height="630" loading="lazy"')
        ->not->toContain('999')
        ->toContain('<div class="article-table"><table>')
        ->and($toc)->toBe([
            ['id' => 'primer-paso', 'text' => 'Primer paso', 'level' => 2],
            ['id' => 'primer-paso-2', 'text' => 'Primer paso', 'level' => 2],
            ['id' => 'detalle', 'text' => 'Detalle', 'level' => 3],
        ]);
});

it('cuenta las palabras del texto, sin las etiquetas', function () {
    expect(BlogHtml::wordCount('<h2>Cierre fiscal</h2><p>Revisá  los   bancos.</p>'))->toBe(5)
        ->and(BlogHtml::wordCount(''))->toBe(0)
        ->and(BlogHtml::wordCount('<p></p>'))->toBe(0);
});
