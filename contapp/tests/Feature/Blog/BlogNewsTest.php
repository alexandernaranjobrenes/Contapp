<?php

use App\Domains\Blog\Models\BlogPost;
use App\Domains\Feedback\Models\NewsPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Avisar un artículo en Noticias
|--------------------------------------------------------------------------
|
| Un artículo puede avisarse en la pestaña Noticias de la aplicación: la
| noticia lleva el título y la descripción que se escribieron para ella, la
| portada del artículo como imagen y el artículo como enlace. Existe
| mientras el artículo esté publicado (CLAUDE.md secc. 41).
|
*/

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.media' => 's3']);
});

function publishWithNews(array $overrides = []): BlogPost
{
    $cover = blogImage();

    test()->post(route('backoffice.blog.store'), blogPayload([
        'status' => 'published',
        'cover_image_id' => $cover->id,
        'cover_alt' => 'Portada',
        'announce_in_news' => 1,
        'news_title' => 'Nuevo artículo: el cierre fiscal',
        'news_body' => 'Lo que hay que revisar antes del cierre.',
        ...$overrides,
    ]))->assertSessionHasNoErrors();

    return BlogPost::latest('id')->firstOrFail();
}

it('al publicar con el aviso, crea la noticia con la portada y el enlace del artículo', function () {
    loginAsPropietario();
    $post = publishWithNews();

    $news = NewsPost::sole();

    expect($news->blog_post_id)->toBe($post->id)
        ->and($news->title)->toBe('Nuevo artículo: el cierre fiscal')
        ->and($news->image_path)->toBeNull()
        ->and($news->link_url)->toBeNull();

    // Lo que ve una cuenta en el panel «Comentarios y noticias».
    $this->actingAs(User::factory()->create());

    $item = $this->getJson(route('news.index'))->assertOk()->json('news.0');

    expect($item['link_url'])->toBe($post->url())
        ->and($item['link_kind'])->toBe('article')
        ->and($item['link_is_internal'])->toBeFalse()
        ->and($item['image_url'])->toBe(Storage::disk('s3')->url($post->cover->path));
});

it('un borrador con el aviso todavía no tiene noticia', function () {
    loginAsPropietario();
    $cover = blogImage();

    $this->post(route('backoffice.blog.store'), blogPayload([
        'cover_image_id' => $cover->id,
        'cover_alt' => 'Portada',
        'announce_in_news' => 1,
        'news_title' => 'Pronto',
        'news_body' => 'Un artículo que todavía no sale.',
    ]))->assertSessionHasNoErrors();

    expect(NewsPost::count())->toBe(0);
});

it('pide el título y la descripción de la noticia', function () {
    loginAsPropietario();

    $this->post(route('backoffice.blog.store'), blogPayload(['announce_in_news' => 1]))
        ->assertSessionHasErrors(['news_title', 'news_body']);
});

it('editar el artículo actualiza la noticia sin volverla nueva', function () {
    loginAsPropietario();
    $post = publishWithNews();
    $created = NewsPost::sole()->created_at;
    $this->travel(2)->hours();

    $this->put(route('backoffice.blog.update', $post), blogPayload([
        'title' => $post->title,
        'slug' => $post->slug,
        'status' => 'published',
        'cover_image_id' => $post->cover_image_id,
        'cover_alt' => 'Portada',
        'announce_in_news' => 1,
        'news_title' => 'Título corregido',
        'news_body' => 'Lo que hay que revisar antes del cierre.',
    ]))->assertSessionHasNoErrors();

    $news = NewsPost::sole();

    expect($news->title)->toBe('Título corregido')
        ->and($news->created_at->equalTo($created))->toBeTrue();
});

it('pasar a borrador, apagar el aviso o eliminar el artículo quita la noticia', function (string $how) {
    loginAsPropietario();
    $post = publishWithNews();
    expect(NewsPost::count())->toBe(1);

    $base = blogPayload([
        'title' => $post->title,
        'slug' => $post->slug,
        'cover_image_id' => $post->cover_image_id,
        'cover_alt' => 'Portada',
        'news_title' => 'Nuevo artículo: el cierre fiscal',
        'news_body' => 'Lo que hay que revisar antes del cierre.',
    ]);

    match ($how) {
        'borrador' => $this->put(route('backoffice.blog.update', $post), [...$base, 'status' => 'draft', 'announce_in_news' => 1]),
        'sin aviso' => $this->put(route('backoffice.blog.update', $post), [...$base, 'status' => 'published', 'announce_in_news' => 0]),
        'eliminado' => $this->delete(route('backoffice.blog.destroy', $post)),
    };

    expect(NewsPost::count())->toBe(0);
})->with(['borrador', 'sin aviso', 'eliminado']);

it('la noticia de un artículo no se edita ni se elimina desde Noticias', function () {
    loginAsPropietario();
    publishWithNews();
    $news = NewsPost::sole();

    $this->put(route('backoffice.news.update', $news), ['title' => 'Otro', 'body' => 'Otro'])->assertForbidden();
    $this->delete(route('backoffice.news.destroy', $news))->assertForbidden();

    expect(NewsPost::sole()->title)->toBe('Nuevo artículo: el cierre fiscal');
});
