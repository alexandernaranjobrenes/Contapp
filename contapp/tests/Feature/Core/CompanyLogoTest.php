<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use App\Domains\Reporting\Support\ReportLogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| El logo de la compañía, para los reportes
|--------------------------------------------------------------------------
|
| Lo sube solo el Superusuario, en Administración → Apariencia. Va al disco
| de imágenes (acá "s3", reemplazado por uno de mentira) como
| «companies/user_owner_{dueño}/company_logo_{compañía}.{ext}».
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');

    config(['filesystems.media' => 's3']);
});

/** Una compañía con licencia, y su Superusuario con la sesión iniciada. */
function logoOwner(): array
{
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo', 'tax_id' => '3-101-123456'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );

    test()->actingAs($activation['user']);

    return ['owner' => $activation['user'], 'company' => $activation['company'], 'license' => $activation['license']];
}

/**
 * Una imagen de $width × $height con el fondo que se pida ('transparent',
 * 'white' o un color [r, g, b]) y, si se indica, un rectángulo de color
 * centrado adentro: el «logo» rodeado de margen.
 *
 * @param  array{0: int, 1: int}|null  $content  ancho y alto del rectángulo
 */
function logoFile(string $name, int $width, int $height, string|array $background = [20, 60, 140], ?array $content = null, array $ink = [20, 60, 140]): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $fill = match (true) {
        $background === 'transparent' => imagecolorallocatealpha($image, 0, 0, 0, 127),
        $background === 'white' => imagecolorallocate($image, 255, 255, 255),
        default => imagecolorallocate($image, ...$background),
    };
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $fill);

    if ($content !== null) {
        [$contentWidth, $contentHeight] = $content;
        $x = intdiv($width - $contentWidth, 2);
        $y = intdiv($height - $contentHeight, 2);
        imagefilledrectangle($image, $x, $y, $x + $contentWidth - 1, $y + $contentHeight - 1, imagecolorallocate($image, ...$ink));
    }

    ob_start();
    str_ends_with($name, '.png') ? imagepng($image) : imagejpeg($image, null, 95);

    return UploadedFile::fake()->createWithContent($name, ob_get_clean());
}

/** Ancho y alto del logo que quedó guardado. */
function storedLogoSize(Company $company): array
{
    [$width, $height] = getimagesizefromstring(Storage::disk('s3')->get($company->fresh()->logo_path));

    return [$width, $height];
}

// ── Subir ────────────────────────────────────────────────────────────────

it('el Superusuario sube el logo: va al bucket con el nombre de su compañía y queda en la bitácora', function () {
    ['owner' => $owner, 'company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), [
        'logo' => logoFile('marca.png', 900, 300),
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $path = "companies/user_owner_{$owner->id}/company_logo_{$company->id}.png";
    $company->refresh();

    Storage::disk('s3')->assertExists($path);
    expect($company->logo_path)->toBe($path)
        ->and($company->logo_updated_at)->not->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([$path])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $log = AuditLog::where('action', 'company.logo_updated')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($owner->id)
        ->and($log->new_values)->toBe(['logo_path' => $path, 'width' => 600, 'height' => 200]);

    $this->get(route('appearance.edit'))->assertInertia(fn ($page) => $page
        ->where('logo.url', Storage::disk('s3')->url($path).'?v='.$company->logo_updated_at->getTimestamp())
        ->where('logo.can_edit', true)
        ->where('logo.report_box', ['width' => ReportLogo::BOX_WIDTH, 'height' => ReportLogo::BOX_HEIGHT])
        ->where('reportHeader.name', 'El Clavo')
        ->where('reportHeader.tax_id', '3-101-123456'));
});

it('guarda el logo con la extensión de su formato', function (string $name, string $extension) {
    ['owner' => $owner, 'company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => logoFile($name, 400, 200)])->assertSessionHasNoErrors();

    expect($company->fresh()->logo_path)->toBe("companies/user_owner_{$owner->id}/company_logo_{$company->id}.{$extension}");
})->with([
    'png' => ['marca.png', 'png'],
    'jpg' => ['marca.jpg', 'jpg'],
    'jpeg se guarda como jpg' => ['marca.jpeg', 'jpg'],
]);

it('una compañía sin licencia agrupa su logo bajo quien lo sube', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertSessionHasNoErrors();

    expect($company->fresh()->logo_path)->toBe("companies/user_owner_{$user->id}/company_logo_{$company->id}.png");
});

// ── Las medidas ──────────────────────────────────────────────────────────

it('lo ajusta sin deformarlo para que entre en 600 × 240, y no agranda uno chico', function (int $width, int $height, array $stored) {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', $width, $height)])->assertSessionHasNoErrors();

    expect(storedLogoSize($company))->toBe($stored);
})->with([
    'apaisado: manda el ancho' => [1800, 600, [600, 200]],
    'muy apaisado' => [2400, 300, [600, 75]],
    'cuadrado: manda el alto' => [1000, 1000, [240, 240]],
    'vertical' => [300, 900, [80, 240]],
    'chico: no se agranda' => [220, 90, [220, 90]],
]);

it('le quita el margen transparente de alrededor', function () {
    ['company' => $company] = logoOwner();

    // Un logo de 400 × 100 flotando en un lienzo transparente de 800 × 800:
    // sin recortar entraría como un cuadrado de 240, con el logo de 120 × 30.
    $this->post(route('appearance.logo.update'), [
        'logo' => logoFile('marca.png', 800, 800, 'transparent', [400, 100]),
    ])->assertSessionHasNoErrors();

    expect(storedLogoSize($company))->toBe([400, 100]);

    // Y el fondo que queda sigue siendo el suyo: no se rellenó.
    $image = imagecreatefromstring(Storage::disk('s3')->get($company->fresh()->logo_path));
    expect(imagecolorsforindex($image, imagecolorat($image, 5, 5))['blue'])->toBeGreaterThan(120);
});

it('le quita el margen blanco de alrededor a un JPG', function () {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), [
        'logo' => logoFile('marca.jpg', 900, 600, 'white', [300, 150]),
    ])->assertSessionHasNoErrors();

    [$width, $height] = storedLogoSize($company);

    // El JPG difumina un poco los bordes: se admite un par de píxeles.
    expect($width)->toBeGreaterThanOrEqual(298)->toBeLessThanOrEqual(304)
        ->and($height)->toBeGreaterThanOrEqual(148)->toBeLessThanOrEqual(154);
});

it('no recorta un fondo de color: es parte del logo', function () {
    ['company' => $company] = logoOwner();

    // Un cuadrado azul con un símbolo blanco en el medio.
    $this->post(route('appearance.logo.update'), [
        'logo' => logoFile('marca.png', 480, 480, [20, 60, 140], [200, 200], [255, 255, 255]),
    ])->assertSessionHasNoErrors();

    expect(storedLogoSize($company))->toBe([240, 240]);
});

it('en un logo de letras blancas sobre transparente, lo blanco es el logo y se conserva', function () {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), [
        'logo' => logoFile('marca.png', 600, 400, 'transparent', [300, 120], [255, 255, 255]),
    ])->assertSessionHasNoErrors();

    expect(storedLogoSize($company))->toBe([300, 120]);
});

it('rechaza una imagen en blanco o un logo demasiado chico', function (Closure $file) {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => $file()])->assertSessionHasErrors('logo');

    expect($company->fresh()->logo_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'todo transparente' => [fn () => logoFile('marca.png', 400, 200, 'transparent')],
    'todo blanco' => [fn () => logoFile('marca.jpg', 400, 200, 'white')],
    'chico' => [fn () => logoFile('marca.png', 80, 40)],
    'chico una vez sin margen' => [fn () => logoFile('marca.png', 800, 800, 'transparent', [60, 30])],
]);

it('rechaza lo que no es una imagen en un formato aceptado, o pesa de más', function (Closure $file) {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => $file()])->assertSessionHasErrors('logo');

    expect($company->fresh()->logo_path)->toBeNull();
})->with([
    'sin archivo' => [fn () => null],
    'un PDF' => [fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')],
    'un GIF' => [fn () => UploadedFile::fake()->image('logo.gif', 300, 300)],
    'un SVG' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="100"/>')],
    'más de 4 MB' => [fn () => UploadedFile::fake()->image('logo.jpg', 300, 300)->size(5000)],
]);

// ── Solo el Superusuario ─────────────────────────────────────────────────

it('un Administrador ve el logo en Apariencia pero no puede cambiarlo ni quitarlo', function () {
    ['owner' => $owner, 'company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)]);
    $path = $company->fresh()->logo_path;

    $module = Module::firstOrCreate(['code' => 'accounting'], ['name' => 'Contabilidad']);
    $admin = app(PermissionGrantService::class)->createUser(
        $owner, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [$module->id => 'read'],
    );

    $this->actingAs($admin);

    $this->get(route('appearance.edit'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('logo.can_edit', false)
        ->where('logo.url', fn ($url) => str_contains($url, $path)));

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('otra.png', 400, 200)])->assertForbidden();
    $this->delete(route('appearance.logo.destroy'))->assertForbidden();

    expect($company->fresh()->logo_path)->toBe($path);
    Storage::disk('s3')->assertExists($path);
});

it('un Usuario común ni siquiera llega, y un invitado va al login', function () {
    ['owner' => $owner, 'company' => $company] = logoOwner();

    $module = Module::firstOrCreate(['code' => 'accounting'], ['name' => 'Contabilidad']);
    $user = app(PermissionGrantService::class)->createUser(
        $owner, $company,
        ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'],
        'user', [$module->id => 'read_write'],
    );

    $this->actingAs($user);
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertForbidden();

    $this->delete(route('logout'));
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertRedirect(route('login'));

    expect(Storage::disk('s3')->allFiles())->toBe([]);
});

it('el Superusuario de una licencia no cambia el logo de una compañía ajena donde es Administrador', function () {
    ['owner' => $owner, 'company' => $company] = logoOwner();

    $guest = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa del Invitado'],
        ['name' => 'Invitado', 'email' => 'invitado@example.com', 'password' => 'Secreto#1234'],
    );
    app(PermissionGrantService::class)->inviteUser($owner, $company, 'invitado@example.com', 'admin', []);

    $this->actingAs($guest['user']);

    // En la suya, sí.
    $this->put(route('company-switch'), ['company_id' => $guest['company']->id]);
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertSessionHasNoErrors();

    // En la ajena, donde es Administrador, no.
    $this->put(route('company-switch'), ['company_id' => $company->id]);
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertForbidden();

    expect($company->fresh()->logo_path)->toBeNull()
        ->and($guest['company']->fresh()->logo_path)
        ->toBe("companies/user_owner_{$guest['user']->id}/company_logo_{$guest['company']->id}.png");
});

it('con la licencia vencida no se cambia el logo: es un dato de la compañía', function () {
    ['company' => $company, 'license' => $license] = logoOwner();
    $license->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertForbidden();

    expect($company->fresh()->logo_path)->toBeNull();
});

// ── Reemplazar y quitar ──────────────────────────────────────────────────

it('subir otro reemplaza el anterior: la compañía siempre tiene un solo logo en el bucket', function () {
    ['owner' => $owner, 'company' => $company] = logoOwner();
    $base = "companies/user_owner_{$owner->id}/company_logo_{$company->id}";

    // Un logo cargado antes de que existiera esta pantalla, en otra ruta.
    Storage::disk('s3')->put('logos/viejo.png', 'logo-viejo');
    $company->update(['logo_path' => 'logos/viejo.png']);

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('uno.jpg', 400, 200)]);
    expect(Storage::disk('s3')->allFiles())->toBe(["{$base}.jpg"]);
    $first = $company->fresh();

    // Con el mismo formato: el mismo nombre, pisado, y otra versión.
    $this->travel(5)->seconds();
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('dos.jpg', 500, 200)]);
    $second = $company->fresh();

    expect($second->logo_path)->toBe("{$base}.jpg")
        ->and($second->logo_updated_at->gt($first->logo_updated_at))->toBeTrue()
        ->and(storedLogoSize($company))->toBe([500, 200]);

    // Con otro formato: cambia la extensión y el anterior se borra.
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('tres.png', 400, 200)]);
    expect(Storage::disk('s3')->allFiles())->toBe(["{$base}.png"]);
});

it('quita el logo: se borra del bucket y los reportes vuelven a salir sin él', function () {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)]);

    $this->delete(route('appearance.logo.destroy'))->assertSessionHasNoErrors()->assertSessionHas('success');

    $company->refresh();

    expect($company->logo_path)->toBeNull()
        ->and($company->logo_updated_at)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([])
        ->and(AuditLog::where('action', 'company.logo_removed')->sole()->company_id)->toBe($company->id);

    $this->get(route('appearance.edit'))->assertInertia(fn ($page) => $page->where('logo.url', null));

    // Quitar cuando no hay logo no hace nada, ni llena la bitácora.
    $this->delete(route('appearance.logo.destroy'))->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'company.logo_removed')->count())->toBe(1);
});

it('si el bucket no responde, lo dice y conserva el logo que había', function () {
    ['company' => $company] = logoOwner();
    $company->update(['logo_path' => 'logos/anterior.png', 'logo_updated_at' => now()]);

    $this->partialMock(MediaStorage::class, function ($mock) {
        $mock->shouldReceive('put')->once()->andReturnFalse();
        $mock->shouldNotReceive('delete');
    });

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)])->assertSessionHasErrors('logo');

    expect($company->fresh()->logo_path)->toBe('logos/anterior.png')
        ->and(AuditLog::where('action', 'company.logo_updated')->exists())->toBeFalse();
});

// ── En los reportes ──────────────────────────────────────────────────────

it('sin logo, el encabezado no deja un hueco: el nombre arranca en el margen', function () {
    ['company' => $company] = logoOwner();

    $render = fn () => view('reports.partials.header', ['header' => new ReportHeader(
        companyName: 'El Clavo',
        taxId: null,
        address: null,
        logoPath: $company->fresh()->logo_path,
        title: 'Estado de resultados',
        paramsSummary: 'Octubre 2026',
        generatedByName: 'Dueña',
        generatedAt: Date::now(),
    )])->render();

    // Una sola celda: la del nombre. Ni imagen ni celda vacía para el logo.
    expect(substr_count($render(), '<td'))->toBe(1)
        ->and($render())->not->toContain('<img');

    // Con logo, dos: el logo y el nombre.
    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 400, 200)]);

    expect(substr_count($render(), '<td'))->toBe(2)
        ->and($render())->toContain('<img');
});

it('calcula el tamaño con que se dibuja en un reporte: entra en la caja sin deformarse', function (int $width, int $height, array $drawn) {
    expect(ReportLogo::fit($width, $height))->toBe($drawn);
})->with([
    'apaisado 3:1' => [600, 200, [160, 53]],
    'muy apaisado' => [600, 75, [160, 20]],
    'cuadrado' => [240, 240, [56, 56]],
    'vertical' => [80, 240, [19, 56]],
    'más chico que la caja: no se agranda' => [120, 40, [120, 40]],
]);

it('el encabezado del PDF dibuja el logo subido con su ancho y alto ya calculados', function () {
    ['company' => $company] = logoOwner();

    $this->post(route('appearance.logo.update'), ['logo' => logoFile('marca.png', 1800, 600)]);

    $html = view('reports.partials.header', ['header' => new ReportHeader(
        companyName: 'El Clavo',
        taxId: '3-101-123456',
        address: null,
        logoPath: $company->fresh()->logo_path,
        title: 'Balance de comprobación',
        paramsSummary: 'Abril 2026',
        generatedByName: 'Dueña',
        generatedAt: Date::now(),
    )])->render();

    // 600 × 200 guardado → 160 × 53 en el papel, en una celda de su ancho.
    expect($html)->toContain('src="data:image/png;base64,')
        ->and($html)->toContain('style="width: 160px; height: 53px;"')
        ->and($html)->toContain('width: 172px;');
});
