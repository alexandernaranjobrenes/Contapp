<?php

use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Inventory\Services\BarcodeScanService;

/*
|--------------------------------------------------------------------------
| Escanear el código de barras con el teléfono, desde la computadora
|--------------------------------------------------------------------------
|
| BarcodeScanService: como el escaneo de documentos de Conti. Un QR con un
| pase de un solo uso; el teléfono lee el código y manda solo el texto; la
| computadora que lo generó lo recibe una vez.
|
*/

it('el QR apunta a la IP de la red local y lleva un pase que solo sirve para ese escaneo', function () {
    logInAsCompanyUser();
    config(['conti.scanner.ip' => '192.168.18.14', 'app.url' => 'http://localhost:8000']);

    $scan = $this->postJson(route('items.barcode-scans.start'), ['para' => 'Arroz 1 kg'])->assertCreated()->json();

    expect($scan['url'])->toStartWith('http://192.168.18.14:8000/escanear-codigo/')
        ->and($scan['minutos'])->toBe(BarcodeScanService::MINUTES);

    $token = basename($scan['url']);
    expect(app(BarcodeScanService::class)->forToken($token))->toMatchArray(['para' => 'Arroz 1 kg'])
        ->and(app(BarcodeScanService::class)->forToken($token.'x'))->toBeNull();

    // La página del teléfono: sin sesión, con el lector y solo el nombre del artículo.
    auth()->logout();
    $this->get($scan['url'])->assertOk()
        ->assertSee('Escanear el código de barras')
        ->assertSee('Arroz 1 kg')
        ->assertSee('/vendor/zxing/zxing-browser.min.js', false);
    $this->get(route('barcode-phone.show', 'no-existe'))->assertOk()->assertSee('Este código ya no sirve');

    // El lector que carga la página está en public/vendor (npm run vendor:zxing).
    expect(file_exists(public_path('vendor/zxing/zxing-browser.min.js')))->toBeTrue();
});

it('el teléfono manda el código y la computadora que lo pidió lo recibe una sola vez', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    $scan = $this->postJson(route('items.barcode-scans.start'))->json();
    $this->getJson(route('items.barcode-scans.status', $scan['id']))->assertExactJson(['estado' => 'esperando']);

    // Desde el teléfono, sin sesión.
    auth()->logout();
    $send = route('barcode-phone.store', basename($scan['url']));
    $this->postJson($send, ['codigo' => ' 7501234567893 '])->assertOk();
    // Un solo uso.
    $this->postJson($send, ['codigo' => '7501234567893'])->assertStatus(410);

    // Nadie más lo ve.
    ['user' => $other] = logInAsCompanyUser($company);
    $this->actingAs($other)->getJson(route('items.barcode-scans.status', $scan['id']))->assertExactJson(['estado' => 'vencido']);

    $this->actingAs($user)->getJson(route('items.barcode-scans.status', $scan['id']))
        ->assertOk()
        ->assertExactJson(['estado' => 'listo', 'codigo' => '7501234567893']);
    $this->actingAs($user)->getJson(route('items.barcode-scans.status', $scan['id']))->assertExactJson(['estado' => 'vencido']);
});

it('el teléfono solo manda lo que admite un código de barras', function () {
    logInAsCompanyUser();
    $scan = $this->postJson(route('items.barcode-scans.start'))->json();
    auth()->logout();
    $send = route('barcode-phone.store', basename($scan['url']));

    $this->postJson($send, ['codigo' => 'PIÑA-01'])->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    $this->postJson($send, [])->assertStatus(422);
    // Lo malo no gasta el pase.
    $this->postJson($send, ['codigo' => 'ABC-123'])->assertOk();
});

it('el QR pide Lectura y escritura en Artículos', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, ['inventory.items' => 'read']);

    $this->postJson(route('items.barcode-scans.start'))->assertForbidden();
    $this->getJson(route('items.barcode-scans.status', 'cualquiera'))->assertForbidden();
});
