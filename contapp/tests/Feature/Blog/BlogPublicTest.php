<?php

use App\Domains\Blog\Models\BlogPost;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El blog del sitio público
|--------------------------------------------------------------------------
|
| Lo publicado se ve en /blog, armado en el servidor y con todo lo que mira
| un buscador: título, descripción, canónica, Open Graph y datos
| estructurados (CLAUDE.md secc. 41).
|
*/

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.media' => 's3', 'app.url' => 'https://pivoxcr.com']);
});

it('muestra un artículo publicado con su SEO y sus datos estructurados', function () {
    $category = blogCategory();
    $post = blogPost([
        'category_id' => $category->id,
        'seo_title' => 'Factura electrónica v4.4 paso a paso',
        'meta_description' => 'Cómo emitir una factura electrónica v4.4 en Costa Rica, paso a paso.',
        'author_name' => 'Ana Mora',
        'focus_keyword' => 'factura electrónica',
    ]);

    $response = $this->get('/blog/'.$post->slug)->assertOk();

    $response
        ->assertSee('<title>Factura electrónica v4.4 paso a paso | Pivox</title>', false)
        ->assertSee('<meta name="description" content="Cómo emitir una factura electrónica v4.4 en Costa Rica, paso a paso.">', false)
        ->assertSee('<link rel="canonical" href="https://pivoxcr.com/blog/'.$post->slug.'">', false)
        ->assertSee('<meta property="og:type" content="article">', false)
        ->assertSee('<meta property="og:image" content="'.\App\Domains\Site\Support\Seo::url(Storage::disk('s3')->url($post->cover->path)).'">', false)
        ->assertSee('<meta name="robots" content="index, follow', false)
        ->assertSee('<h1 class="article-title">'.e($post->title).'</h1>', false)
        ->assertSee('id="antes-de-empezar"', false);

    $jsonLd = json_decode(str($response->getContent())->betweenFirst('<script type="application/ld+json">', '</script>')->toString(), true);
    $types = collect($jsonLd['@graph'])->pluck('@type');
    $posting = collect($jsonLd['@graph'])->firstWhere('@type', 'BlogPosting');

    expect($types->all())->toContain('BlogPosting', 'BreadcrumbList', 'Organization')
        ->and($posting['headline'])->toBe($post->title)
        ->and($posting['author'])->toBe(['@type' => 'Person', 'name' => 'Ana Mora'])
        ->and($posting['articleSection'])->toBe($category->name)
        ->and($posting['image'][0]['width'])->toBe(1200);
});

it('un artículo marcado «no indexar» le dice noindex al buscador', function () {
    $post = blogPost(['noindex' => true]);

    $this->get('/blog/'.$post->slug)->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
});

it('un borrador, o una dirección que no existe, da 404', function () {
    $draft = blogPost(['status' => BlogPost::STATUS_DRAFT, 'slug' => 'borrador']);

    $this->get('/blog/'.$draft->slug)->assertNotFound();
    $this->get('/blog/no-existe')->assertNotFound();
});

it('el índice lista lo publicado, con el primero destacado y sus categorías', function () {
    $category = blogCategory();
    blogPost(['title' => 'Primero', 'slug' => 'primero', 'published_at' => now()->subDays(3)]);
    blogPost(['title' => 'Segundo', 'slug' => 'segundo', 'published_at' => now()->subDay(), 'category_id' => $category->id]);
    blogPost(['title' => 'Borrador', 'slug' => 'borrador', 'status' => BlogPost::STATUS_DRAFT]);

    $index = $this->get('/blog')
        ->assertOk()
        ->assertSeeInOrder(['Segundo', 'Primero'])
        ->assertDontSee('Borrador')
        ->assertSee('/blog/categoria/'.$category->slug, false)
        ->assertSee('<link rel="canonical" href="https://pivoxcr.com/blog">', false);

    // Solo el primero va grande; los demás, en la grilla.
    expect(substr_count($index->getContent(), 'blog-card is-featured'))->toBe(1)
        ->and(substr_count($index->getContent(), 'class="blog-card"'))->toBe(1);

    $this->get('/blog/categoria/'.$category->slug)
        ->assertOk()
        ->assertSee('Segundo')
        ->assertDontSee('Primero')
        ->assertSee('<title>'.$category->name.' | Blog de Pivox</title>', false);

    $this->get('/blog/categoria/no-existe')->assertNotFound();
});

it('pagina el índice con ?pagina=, y una página de más da 404', function () {
    foreach (range(1, 10) as $n) {
        blogPost(['title' => "Artículo {$n}", 'slug' => "articulo-{$n}", 'published_at' => now()->subDays($n)]);
    }

    $this->get('/blog')->assertOk()->assertSee('<link rel="next" href="https://pivoxcr.com/blog?pagina=2">', false);
    $this->get('/blog?pagina=2')
        ->assertOk()
        ->assertSee('Artículo 10')
        ->assertSee('<link rel="canonical" href="https://pivoxcr.com/blog?pagina=2">', false)
        ->assertSee('<link rel="prev" href="https://pivoxcr.com/blog">', false);
    $this->get('/blog?pagina=3')->assertNotFound();
});

it('el feed RSS trae lo publicado', function () {
    $post = blogPost();
    blogPost(['title' => 'Oculto', 'slug' => 'oculto', 'noindex' => true]);

    $this->get('/blog/feed')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
        ->assertSee('<guid isPermaLink="false">pivox-blog-'.$post->id.'</guid>', false)
        ->assertSee('<link>https://pivoxcr.com/blog/'.$post->slug.'</link>', false)
        ->assertDontSee('Oculto');
});

it('el sitemap lista los artículos publicados con su portada, y la landing los últimos', function () {
    $post = blogPost();
    blogPost(['title' => 'Borrador', 'slug' => 'borrador', 'status' => BlogPost::STATUS_DRAFT]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertSee('<loc>https://pivoxcr.com/blog/'.$post->slug.'</loc>', false)
        ->assertSee('<image:loc>'.Storage::disk('s3')->url($post->cover->path).'</image:loc>', false)
        ->assertDontSee('/blog/borrador', false);

    $this->get('/')->assertOk()->assertSee($post->title)->assertSee('Del blog');
});
