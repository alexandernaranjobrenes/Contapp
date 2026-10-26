<?php

use App\Domains\Blog\Models\BlogImage;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Blog\Models\BlogPostSlug;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El blog desde el backoffice
|--------------------------------------------------------------------------
|
| Escribir, publicar y eliminar artículos (CLAUDE.md secc. 41). Las imágenes
| van al bucket como blog/imag_{id}.{ext} y no queda ninguna sin usar.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');
    config(['filesystems.media' => 's3']);
});

it('sube una imagen del editor al bucket como blog/imag_{id}', function () {
    $propietario = loginAsPropietario();

    $response = $this->post(route('backoffice.blog.images.store'), ['image' => blogUpload('captura.png', 800, 500)])
        ->assertCreated()
        ->assertJsonStructure(['id', 'url', 'width', 'height']);

    $image = BlogImage::sole();

    expect($image->path)->toBe("blog/imag_{$image->id}.png")
        ->and($image->post_id)->toBeNull()
        ->and($image->uploaded_by)->toBe($propietario->id)
        ->and([$image->width, $image->height])->toBe([800, 500])
        ->and($response->json('id'))->toBe($image->id);

    Storage::disk('s3')->assertExists("blog/imag_{$image->id}.png");
});

it('rechaza lo que no es una imagen, en JSON', function () {
    loginAsPropietario();

    $this->postJson(route('backoffice.blog.images.store'), ['image' => \Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect(BlogImage::count())->toBe(0);
});

it('guarda un borrador sin portada, con la dirección armada del título', function () {
    $propietario = loginAsPropietario();

    $this->post(route('backoffice.blog.store'), blogPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $post = BlogPost::sole();

    expect($post->status)->toBe(BlogPost::STATUS_DRAFT)
        ->and($post->slug)->toBe('cierre-fiscal-en-costa-rica')
        ->and($post->published_at)->toBeNull()
        ->and($post->word_count)->toBe(11)
        ->and($post->created_by)->toBe($propietario->id);
});

it('para publicar pide una portada y texto', function () {
    loginAsPropietario();

    $this->post(route('backoffice.blog.store'), blogPayload(['status' => 'published']))
        ->assertSessionHasErrors('cover_image_id');

    $cover = blogImage();

    $this->post(route('backoffice.blog.store'), blogPayload(['status' => 'published', 'cover_image_id' => $cover->id, 'cover_alt' => 'Portada', 'content' => '<p></p>']))
        ->assertSessionHasErrors('content');

    $this->post(route('backoffice.blog.store'), blogPayload(['status' => 'published', 'cover_image_id' => $cover->id, 'cover_alt' => '']))
        ->assertSessionHasErrors('cover_alt');

    expect(BlogPost::count())->toBe(0);
});

it('publica, y las imágenes que usa pasan a ser del artículo', function () {
    loginAsPropietario();
    $cover = blogImage();
    $inline = blogImage();

    $this->post(route('backoffice.blog.store'), blogPayload([
        'status' => 'published',
        'cover_image_id' => $cover->id,
        'cover_alt' => 'Un contador revisando el cierre',
        'content' => '<p>Texto</p><figure><img src="https://x.test/a.jpg" data-image-id="'.$inline->id.'" alt="Balance"></figure><script>alert(1)</script>',
    ]))->assertSessionHasNoErrors();

    $post = BlogPost::sole();

    expect($post->isPublished())->toBeTrue()
        ->and($post->published_at)->not->toBeNull()
        ->and($post->content)->toBe('<p>Texto</p><figure><img data-image-id="'.$inline->id.'" alt="Balance"></figure>')
        ->and($cover->fresh()->post_id)->toBe($post->id)
        ->and($inline->fresh()->post_id)->toBe($post->id);
});

it('borra del bucket las imágenes que el artículo deja de usar, y no toca las de otro', function () {
    loginAsPropietario();
    $post = blogPost(['content' => '<p>Hola</p>']);
    $old = blogImage($post);
    $other = blogPost(['slug' => 'otro-articulo']);
    $foreign = blogImage($other);

    $this->put(route('backoffice.blog.update', $post), blogPayload([
        'title' => $post->title,
        'slug' => $post->slug,
        'status' => 'published',
        'cover_image_id' => $post->cover_image_id,
        'cover_alt' => 'Portada',
        'content' => '<p>Sin la imagen vieja</p><figure><img data-image-id="'.$foreign->id.'" alt="De otro"></figure>',
    ]))->assertSessionHasNoErrors();

    expect(BlogImage::find($old->id))->toBeNull()
        ->and($post->fresh()->content)->toBe('<p>Sin la imagen vieja</p>')
        ->and($foreign->fresh()->post_id)->toBe($other->id);

    Storage::disk('s3')->assertMissing($old->path);
    Storage::disk('s3')->assertExists($foreign->path);
});

it('al cambiar la dirección de un artículo publicado, la anterior redirige con un 301', function () {
    loginAsPropietario();
    $post = blogPost();

    $this->put(route('backoffice.blog.update', $post), blogPayload([
        'title' => $post->title,
        'slug' => 'factura-electronica-paso-a-paso',
        'status' => 'published',
        'cover_image_id' => $post->cover_image_id,
        'cover_alt' => 'Portada',
    ]))->assertSessionHasNoErrors();

    expect($post->fresh()->slug)->toBe('factura-electronica-paso-a-paso')
        ->and(BlogPostSlug::where('slug', 'como-emitir-una-factura-electronica')->value('post_id'))->toBe($post->id);

    $this->get('/blog/como-emitir-una-factura-electronica')
        ->assertStatus(301)
        ->assertRedirect('/blog/factura-electronica-paso-a-paso');
});

it('una dirección escrita a mano tiene que estar libre; una del título se numera', function () {
    loginAsPropietario();
    blogPost(['slug' => 'cierre-fiscal-en-costa-rica']);

    $this->post(route('backoffice.blog.store'), blogPayload(['slug' => 'cierre-fiscal-en-costa-rica']))
        ->assertSessionHasErrors('slug');

    $this->post(route('backoffice.blog.store'), blogPayload(['slug' => 'feed']))
        ->assertSessionHasErrors('slug');

    $this->post(route('backoffice.blog.store'), blogPayload())->assertSessionHasNoErrors();

    expect(BlogPost::latest('id')->value('slug'))->toBe('cierre-fiscal-en-costa-rica-2');
});

it('no publica con una fecha futura', function () {
    loginAsPropietario();
    $cover = blogImage();

    $this->post(route('backoffice.blog.store'), blogPayload([
        'status' => 'published',
        'cover_image_id' => $cover->id,
        'cover_alt' => 'Portada',
        'published_at' => now('America/Costa_Rica')->addDays(3)->format('Y-m-d\TH:i'),
    ]))->assertSessionHasErrors('published_at');
});

it('eliminar un artículo borra sus imágenes del bucket', function () {
    loginAsPropietario();
    $post = blogPost();
    $inline = blogImage($post);
    $paths = [$post->cover->path, $inline->path];

    $this->delete(route('backoffice.blog.destroy', $post))->assertRedirect(route('backoffice.blog.index'));

    expect(BlogPost::count())->toBe(0)->and(BlogImage::count())->toBe(0);
    foreach ($paths as $path) {
        Storage::disk('s3')->assertMissing($path);
    }
});

it('borra las imágenes que se subieron y ningún artículo usó en un día', function () {
    $orphan = blogImage();
    $orphan->forceFill(['created_at' => now()->subHours(25)])->save();
    $recent = blogImage();
    $post = blogPost();

    Artisan::call('pivox:prune-blog-images');

    expect(BlogImage::find($orphan->id))->toBeNull()
        ->and(BlogImage::find($recent->id))->not->toBeNull()
        ->and(BlogImage::find($post->cover_image_id))->not->toBeNull();

    Storage::disk('s3')->assertMissing($orphan->path);
});

it('muestra el editor con las imágenes con su dirección, y la vista previa de un borrador', function () {
    loginAsPropietario();
    $post = blogPost(['status' => BlogPost::STATUS_DRAFT, 'published_at' => null]);
    $inline = blogImage($post);
    $post->update(['content' => '<p>Hola</p><figure><img data-image-id="'.$inline->id.'" alt="x"></figure>']);

    $this->get(route('backoffice.blog.edit', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Backoffice/Blog/Edit')
            ->where('post.id', $post->id)
            ->where('post.is_published', false)
            ->where('post.content', fn (string $html) => str_contains($html, 'src="'.Storage::disk('s3')->url($inline->path).'"')));

    $this->get(route('backoffice.blog.preview', $post))
        ->assertOk()
        ->assertSee('Vista previa')
        ->assertSee('noindex', false);

    // Al público, un borrador no existe.
    $this->get('/blog/'.$post->slug)->assertNotFound();
});

it('el blog del backoffice es solo del Propietario', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('backoffice.blog.index'))->assertRedirect(route('backoffice.login'));
    // La subida habla JSON: sin sesión del backoffice, un 401.
    $this->post(route('backoffice.blog.images.store'), ['image' => blogUpload()])->assertUnauthorized();
    expect(BlogImage::count())->toBe(0);
});
