<?php

use App\Domains\Site\Support\Seo;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| El sitio público
|--------------------------------------------------------------------------
|
| La landing está en /, para cualquiera, armada en el servidor con lo que
| mira un buscador. La aplicación y el backoffice no se indexan
| (CLAUDE.md secc. 40).
|
*/

beforeEach(function () {
    config(['app.url' => 'https://pivoxcr.com']);
});

it('la landing está en / con su título, descripción, canónica y datos estructurados', function () {
    $response = $this->get('/')->assertOk();

    $html = $response->getContent();
    $title = str($html)->betweenFirst('<title>', '</title>')->toString();
    $description = str($html)->betweenFirst('<meta name="description" content="', '">')->toString();

    expect(mb_strlen($title))->toBeLessThanOrEqual(Seo::TITLE_LIMIT)
        ->and(mb_strlen(html_entity_decode($description)))->toBeLessThanOrEqual(Seo::DESCRIPTION_LIMIT);

    $response
        ->assertSee('<link rel="canonical" href="https://pivoxcr.com/">', false)
        ->assertSee('<meta property="og:image" content="https://pivoxcr.com/images/og/pivox.png">', false)
        ->assertSee('"@type":"SoftwareApplication"', false)
        ->assertSee('<h1 id="hero-title"', false)
        ->assertSee('Solicitar licencia')
        ->assertSee('Iniciar sesión')
        ->assertSee('Hecho por <a href="https://ncodedigital.com" target="_blank" rel="noopener">NcodeDigital</a>', false)
        ->assertSee('servicioalcliente@pivoxcr.com');
});

it('con la sesión iniciada, la landing ofrece ir al panel', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertOk()->assertSee('Ir a mi panel')->assertDontSee('Iniciar sesión</a>', false);
});

it('robots.txt: fuera de producción nada se indexa; en producción, todo menos el backoffice', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /');

    app()->detectEnvironment(fn () => 'production');

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /backoffice')
        ->assertSee('Sitemap: https://pivoxcr.com/sitemap.xml')
        ->assertDontSee("Disallow: /\n", false);
});

it('el sitemap trae la landing, los planes y el blog', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>https://pivoxcr.com/</loc>', false)
        ->assertSee('<loc>https://pivoxcr.com/precios</loc>', false)
        ->assertSee('<loc>https://pivoxcr.com/blog</loc>', false);
});

it('la página de privacidad existe y lleva el correo de contacto', function () {
    $this->get('/privacidad')->assertOk()->assertSee('Ley 8968')->assertSee('servicioalcliente@pivoxcr.com');
});

it('las pantallas de la aplicación y del backoffice no se indexan', function () {
    $this->get('/login')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    $this->get('/backoffice/login')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});
