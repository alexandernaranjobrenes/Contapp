<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\ProfilePhoto;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Foto de perfil, en «Mi cuenta»
|--------------------------------------------------------------------------
|
| Va al disco de imágenes (acá "s3", el del bucket, reemplazado por uno de
| mentira) como «users/profile_{id}.{ext}»: una por cuenta, con nombre fijo.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');

    config(['filesystems.media' => 's3']);
});

function photoUser(): User
{
    $user = User::factory()->create(['name' => 'Ana Mora']);

    test()->actingAs($user);

    return $user;
}

/**
 * Un JPG de 400×200 —mitad izquierda roja, mitad derecha azul— con la nota
 * EXIF que deja un teléfono sostenido de costado: «para verla bien, girala
 * un cuarto de vuelta a la derecha» (orientación 6).
 */
function sidewaysPhonePhoto(): UploadedFile
{
    $image = imagecreatetruecolor(400, 200);
    imagefilledrectangle($image, 0, 0, 199, 199, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 200, 0, 399, 199, imagecolorallocate($image, 0, 0, 255));

    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = ob_get_clean();

    // Encabezado TIFF con una sola entrada: Orientation (0x0112) = 6.
    $tiff = "II\x2A\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00";
    $exif = "Exif\x00\x00".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    // El segmento EXIF va justo después del inicio de imagen (FFD8).
    return UploadedFile::fake()->createWithContent('telefono.jpg', substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
}

// ── Subir ────────────────────────────────────────────────────────────────

it('guarda la foto en el bucket como users/profile_{id}, y la cuenta la muestra', function () {
    $user = photoUser();

    $this->post(route('profile.photo.update'), [
        'photo' => UploadedFile::fake()->image('yo.jpg', 800, 800),
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $path = "users/profile_{$user->id}.jpg";
    $user->refresh();

    Storage::disk('s3')->assertExists($path);
    expect($user->photo_path)->toBe($path)
        ->and($user->photo_updated_at)->not->toBeNull()
        // En el bucket, y en ningún otro lado.
        ->and(Storage::disk('s3')->allFiles())->toBe([$path])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $log = AuditLog::where('action', 'user.photo_updated')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->new_values)->toBe(['photo_path' => $path]);

    // La dirección es la del bucket, con la versión para que el navegador
    // no muestre la foto anterior.
    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page
        ->where('account.photo_url', Storage::disk('s3')->url($path).'?v='.$user->photo_updated_at->getTimestamp())
        ->where('photoRules.max_megabytes', 4)
        ->where('photoRules.formats', ProfilePhoto::formatsLabel()));
});

it('guarda la foto con la extensión de su formato', function (string $name, string $extension) {
    $user = photoUser();

    $this->post(route('profile.photo.update'), [
        'photo' => UploadedFile::fake()->image($name, 300, 300),
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->photo_path)->toBe("users/profile_{$user->id}.{$extension}");
})->with([
    'jpg' => ['yo.jpg', 'jpg'],
    'jpeg se guarda como jpg' => ['yo.jpeg', 'jpg'],
    'png' => ['yo.png', 'png'],
]);

it('la deja cuadrada y de 512 px como mucho, sin agrandar una chica', function (int $width, int $height, int $side) {
    $user = photoUser();

    $this->post(route('profile.photo.update'), [
        'photo' => UploadedFile::fake()->image('yo.jpg', $width, $height),
    ])->assertSessionHasNoErrors();

    [$storedWidth, $storedHeight] = getimagesizefromstring(Storage::disk('s3')->get($user->fresh()->photo_path));

    expect([$storedWidth, $storedHeight])->toBe([$side, $side]);
})->with([
    'apaisada grande' => [1600, 900, 512],
    'vertical grande' => [900, 1600, 512],
    'chica: no se agranda' => [300, 200, 200],
]);

it('endereza una foto de teléfono y le quita los metadatos', function () {
    $user = photoUser();
    $upload = sidewaysPhonePhoto();

    // El archivo que llega trae la nota EXIF.
    expect(file_get_contents($upload->getRealPath()))->toContain('Exif');

    $this->post(route('profile.photo.update'), ['photo' => $upload])->assertSessionHasNoErrors();

    $stored = Storage::disk('s3')->get($user->fresh()->photo_path);

    // Lo guardado, no: ni orientación, ni GPS, ni nada que no sean píxeles.
    expect($stored)->not->toContain('Exif');

    // Y el giro quedó aplicado: lo que era la mitad izquierda (roja) quedó
    // arriba, y la derecha (azul), abajo.
    $image = imagecreatefromstring($stored);
    $top = imagecolorsforindex($image, imagecolorat($image, 100, 30));
    $bottom = imagecolorsforindex($image, imagecolorat($image, 100, 170));

    expect(imagesx($image))->toBe(200)
        ->and($top['red'])->toBeGreaterThan(200)->and($top['blue'])->toBeLessThan(60)
        ->and($bottom['blue'])->toBeGreaterThan(200)->and($bottom['red'])->toBeLessThan(60);
});

// ── Reemplazar y quitar ──────────────────────────────────────────────────

it('subir otra reemplaza la anterior: la cuenta siempre tiene un solo archivo', function () {
    $user = photoUser();

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('uno.jpg', 300, 300)]);
    $first = $user->fresh();

    // Con el mismo formato: el mismo nombre, pisado.
    $this->travel(5)->seconds();
    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('dos.jpg', 400, 400)]);
    $second = $user->fresh();

    expect($second->photo_path)->toBe($first->photo_path)
        ->and($second->photo_updated_at->gt($first->photo_updated_at))->toBeTrue()
        ->and(Storage::disk('s3')->allFiles())->toBe(["users/profile_{$user->id}.jpg"]);

    // Con otro formato: cambia la extensión y la anterior se borra.
    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('tres.png', 300, 300)]);

    expect($user->fresh()->photo_path)->toBe("users/profile_{$user->id}.png")
        ->and(Storage::disk('s3')->allFiles())->toBe(["users/profile_{$user->id}.png"]);
});

it('quita la foto: se borra del bucket y la cuenta vuelve a sus iniciales', function () {
    $user = photoUser();

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)]);

    $this->delete(route('profile.photo.destroy'))->assertSessionHasNoErrors()->assertSessionHas('success');

    $user->refresh();

    expect($user->photo_path)->toBeNull()
        ->and($user->photo_updated_at)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([])
        ->and(AuditLog::where('action', 'user.photo_removed')->sole()->user_id)->toBe($user->id);

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('account.photo_url', null));

    // Quitar cuando no hay foto no hace nada, ni llena la bitácora.
    $this->delete(route('profile.photo.destroy'))->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'user.photo_removed')->count())->toBe(1);
});

// ── Lo que no se acepta ──────────────────────────────────────────────────

it('rechaza lo que no es una foto en un formato aceptado, o pesa o mide de más o de menos', function (Closure $file) {
    $user = photoUser();

    $this->post(route('profile.photo.update'), ['photo' => $file()])->assertSessionHasErrors('photo');

    expect($user->fresh()->photo_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'sin archivo' => [fn () => null],
    'un PDF' => [fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
    'texto con nombre de foto' => [fn () => UploadedFile::fake()->createWithContent('yo.jpg', 'no soy una imagen')],
    'un GIF' => [fn () => UploadedFile::fake()->image('yo.gif', 300, 300)],
    'un SVG' => [fn () => UploadedFile::fake()->createWithContent('yo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"/>')],
    'más de 4 MB' => [fn () => UploadedFile::fake()->image('yo.jpg', 300, 300)->size(5000)],
    'demasiado chica' => [fn () => UploadedFile::fake()->image('yo.jpg', 60, 60)],
]);

it('si el bucket no responde, lo dice y conserva la foto que había', function () {
    $user = photoUser();
    $user->forceFill(['photo_path' => "users/profile_{$user->id}.png", 'photo_updated_at' => now()])->save();

    // Parcial: solo falla guardar. Armar la dirección de la foto que ya
    // tenía (lo hace cada pantalla, para el menú) sigue funcionando.
    $this->partialMock(MediaStorage::class, function ($mock) {
        $mock->shouldReceive('put')->once()->andReturnFalse();
        $mock->shouldNotReceive('delete');
    });

    $this->post(route('profile.photo.update'), [
        'photo' => UploadedFile::fake()->image('yo.jpg', 300, 300),
    ])->assertSessionHasErrors('photo');

    expect($user->fresh()->photo_path)->toBe("users/profile_{$user->id}.png")
        ->and(AuditLog::where('action', 'user.photo_updated')->exists())->toBeFalse();
});

// ── De quién es ──────────────────────────────────────────────────────────

it('exige sesión', function () {
    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)])
        ->assertRedirect(route('login'));
    $this->delete(route('profile.photo.destroy'))->assertRedirect(route('login'));

    expect(Storage::disk('s3')->allFiles())->toBe([]);
});

it('solo toca la foto de la propia cuenta, mande lo que mande el formulario', function () {
    $other = User::factory()->create();
    Storage::disk('s3')->put("users/profile_{$other->id}.jpg", 'foto-de-otra-persona');
    $other->forceFill(['photo_path' => "users/profile_{$other->id}.jpg", 'photo_updated_at' => now()])->save();

    $user = photoUser();

    $this->post(route('profile.photo.update'), [
        'photo' => UploadedFile::fake()->image('yo.jpg', 300, 300),
        'id' => $other->id,
        'user_id' => $other->id,
        'photo_path' => "users/profile_{$other->id}.jpg",
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->photo_path)->toBe("users/profile_{$user->id}.jpg")
        ->and(Storage::disk('s3')->get("users/profile_{$other->id}.jpg"))->toBe('foto-de-otra-persona');

    $this->delete(route('profile.photo.destroy'), ['id' => $other->id]);

    expect($other->fresh()->photo_path)->toBe("users/profile_{$other->id}.jpg");
    Storage::disk('s3')->assertExists("users/profile_{$other->id}.jpg");
    Storage::disk('s3')->assertMissing("users/profile_{$user->id}.jpg");
});

it('le hace caso al disco configurado: con el disco público, va al disco público', function () {
    config(['filesystems.media' => 'public']);
    $user = photoUser();

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)]);

    Storage::disk('public')->assertExists("users/profile_{$user->id}.jpg");
    expect(Storage::disk('s3')->allFiles())->toBe([]);
});

// ── Dónde se ve ──────────────────────────────────────────────────────────

it('todas las pantallas reciben la foto de la cuenta, para el ícono de «Mi cuenta» del menú', function () {
    $user = photoUser();

    // Sin foto, el menú muestra su ícono.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('auth.user.photo_url', null));

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)]);
    $user->refresh();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where(
        'auth.user.photo_url',
        Storage::disk('s3')->url($user->photo_path).'?v='.$user->photo_updated_at->getTimestamp(),
    ));

    $this->delete(route('profile.photo.destroy'));
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('auth.user.photo_url', null));
});

it('la lista de usuarios de la compañía muestra la foto de cada cuenta', function () {
    ['user' => $superuser] = logInAsCompanyUser(null, ['is_super_admin' => true, 'name' => 'Ana Mora']);

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)]);
    $superuser->refresh();

    $this->get(route('users.index'))->assertInertia(fn ($page) => $page
        ->where('users.0.name', 'Ana Mora')
        ->where('users.0.photo_url', Storage::disk('s3')->url($superuser->photo_path).'?v='.$superuser->photo_updated_at->getTimestamp()));
});

it('una compañía con la licencia vencida no impide cambiar la propia foto', function () {
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa Vencida'],
        ['name' => 'Ana Mora', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
    );
    $activation['license']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->actingAs($activation['user']);

    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();

    $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->image('yo.jpg', 300, 300)])
        ->assertSessionHasNoErrors();
    $this->delete(route('profile.photo.destroy'))->assertSessionHasNoErrors();
});
