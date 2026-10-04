<?php

use App\Domains\Feedback\Models\NewsPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Las noticias de CONTAPP
|--------------------------------------------------------------------------
|
| Las publica, edita y elimina el backoffice; las cuentas las leen en el
| panel de la barra superior (pestaña «Noticias»). Una imagen como mucho,
| en el bucket como news/{id}_1.{ext}.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');
    config(['filesystems.media' => 's3']);
});

/** Una imagen de verdad (GD), PNG o JPG según el nombre. */
function newsImage(string $name = 'portada.jpg', int $width = 800, int $height = 400): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 120, 40));

    ob_start();
    str_ends_with($name, '.png') ? imagepng($image) : imagejpeg($image, null, 90);

    return UploadedFile::fake()->createWithContent($name, ob_get_clean());
}

function newsPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Nuevo reporte de flujo de caja',
        'body' => 'Ya podés proyectar cobros y pagos desde Bancos.',
        'link_url' => '/reports/cash-flow-projection',
    ], $overrides);
}

it('el backoffice publica una noticia con imagen, en news/{id}_1', function () {
    $propietario = loginAsPropietario();

    $this->post(route('backoffice.news.store'), newsPayload(['image' => newsImage()]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $news = NewsPost::sole();

    expect($news->title)->toBe('Nuevo reporte de flujo de caja')
        ->and($news->image_path)->toBe("news/{$news->id}_1.jpg")
        ->and($news->created_by)->toBe($propietario->id);

    Storage::disk('s3')->assertExists("news/{$news->id}_1.jpg");
});

it('acepta un enlace a otro sitio o a una pantalla de CONTAPP, y nada más', function (?string $link, bool $valid) {
    loginAsPropietario();

    $response = $this->post(route('backoffice.news.store'), newsPayload(['link_url' => $link]));

    if ($valid) {
        $response->assertSessionHasNoErrors();
        expect(NewsPost::count())->toBe(1);
    } else {
        $response->assertSessionHasErrors('link_url');
        expect(NewsPost::count())->toBe(0);
    }
})->with([
    'sin enlace' => [null, true],
    'https' => ['https://contapp.cr/novedades', true],
    'pantalla de CONTAPP' => ['/dashboard', true],
    'javascript' => ['javascript:alert(1)', false],
    'otro sitio sin esquema' => ['//otro-sitio.com', false],
    'ftp' => ['ftp://archivos.com/x', false],
    'texto suelto' => ['contapp punto com', false],
]);

it('exige título y descripción, y una sola imagen', function () {
    loginAsPropietario();

    $this->post(route('backoffice.news.store'), ['title' => '', 'body' => ''])->assertSessionHasErrors(['title', 'body']);
    $this->post(route('backoffice.news.store'), newsPayload(['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]))
        ->assertSessionHasErrors('image');

    expect(NewsPost::count())->toBe(0);
});

it('al editar, reemplaza la imagen (y borra la anterior) o la quita', function () {
    loginAsPropietario();
    $this->post(route('backoffice.news.store'), newsPayload(['image' => newsImage('a.jpg')]));
    $news = NewsPost::sole();

    $this->put(route('backoffice.news.update', $news), newsPayload(['title' => 'Título nuevo', 'image' => newsImage('b.png')]))
        ->assertSessionHasNoErrors();

    $news->refresh();
    expect($news->title)->toBe('Título nuevo')
        ->and($news->image_path)->toBe("news/{$news->id}_1.png");
    Storage::disk('s3')->assertMissing("news/{$news->id}_1.jpg");
    Storage::disk('s3')->assertExists("news/{$news->id}_1.png");

    $this->put(route('backoffice.news.update', $news), newsPayload(['remove_image' => true]))->assertSessionHasNoErrors();

    expect($news->fresh()->image_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('al editar sin imagen nueva, conserva la que tenía', function () {
    loginAsPropietario();
    $this->post(route('backoffice.news.store'), newsPayload(['image' => newsImage('a.jpg')]));
    $news = NewsPost::sole();

    $this->put(route('backoffice.news.update', $news), newsPayload(['body' => 'Otra descripción']))->assertSessionHasNoErrors();

    expect($news->fresh()->image_path)->toBe("news/{$news->id}_1.jpg");
    Storage::disk('s3')->assertExists("news/{$news->id}_1.jpg");
});

it('al eliminar una noticia se borra su imagen', function () {
    loginAsPropietario();
    $this->post(route('backoffice.news.store'), newsPayload(['image' => newsImage()]));
    $news = NewsPost::sole();

    $this->delete(route('backoffice.news.destroy', $news))->assertSessionHas('success');

    expect(NewsPost::count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('las cuentas leen las noticias de la más nueva a la más vieja, y el punto de la barra se entera', function () {
    loginAsPropietario();
    $this->post(route('backoffice.news.store'), newsPayload(['title' => 'Primera', 'link_url' => 'https://contapp.cr']));
    $this->travel(1)->minutes();
    $this->post(route('backoffice.news.store'), newsPayload(['title' => 'Segunda', 'image' => newsImage()]));
    Auth::guard('propietario')->logout();
    Auth::shouldUse('web');

    $user = User::factory()->create(['status' => 'active']);
    $this->actingAs($user);

    $this->getJson(route('news.index'))
        ->assertOk()
        ->assertJsonPath('news.*.title', ['Segunda', 'Primera'])
        ->assertJsonPath('news.0.link_is_internal', true)
        ->assertJsonPath('news.1.link_is_internal', false)
        ->assertJsonPath('news.1.image_url', null);

    expect($this->getJson(route('news.index'))->json('news.0.image_url'))->toContain('news/');

    $latest = NewsPost::where('title', 'Segunda')->sole()->created_at->toIso8601String();

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('latestNewsAt', $latest));
});

it('solo el backoffice publica noticias', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->actingAs($user);

    $this->post(route('backoffice.news.store'), newsPayload())->assertRedirect(route('backoffice.login'));

    expect(NewsPost::count())->toBe(0);
});
