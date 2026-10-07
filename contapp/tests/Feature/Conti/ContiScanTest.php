<?php

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Conti\Services\ContiScanService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Inventory\Models\Item;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Escanear un documento para registrarlo
|--------------------------------------------------------------------------
|
| La IA lee las fotos y llena el formulario de lo que eligió la persona; ella
| lo revisa y viene la confirmación de siempre. Desde la computadora, un QR
| con un pase de un solo uso para subir las fotos con el teléfono. Las fotos
| nunca se guardan.
|
*/

/** Lo que «lee» OpenAI de una factura de proveedor, para una orden de compra. */
function scannedPurchaseOrder(array $override = []): array
{
    return openAiReply(json_encode([
        'proveedor' => 'Distribuidora La Central S.A.',
        'fecha' => '2026-10-05',
        'fecha_esperada' => null,
        'descripcion' => 'Cotización 4521',
        'lineas' => [['articulo' => 'Papel bond carta', 'cantidad' => '10', 'costo' => '2500', 'almacen' => null, 'descripcion' => null]],
        'documento' => ['tipo' => 'Cotización', 'numero' => '4521', 'fecha' => '2026-10-05', 'emisor' => 'Distribuidora La Central S.A.', 'emisor_identificacion' => '3-101-123456', 'receptor' => null, 'moneda' => 'CRC', 'subtotal' => '25000', 'impuesto' => '3250', 'total' => '28250'],
        'no_se_lee' => null,
        ...$override,
    ]), prompt: 3000, completion: 300);
}

function scanFixture(): array
{
    configureOpenAi();
    $f = contiLicensed();
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'PRV-001', 'name' => 'Distribuidora La Central', 'type' => 'supplier', 'tax_id' => '3101123456']);
    Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'ART-1', 'name' => 'Papel bond carta resma']);

    return $f;
}

function photo(): UploadedFile
{
    return UploadedFile::fake()->image('pagina.jpg', 800, 1100);
}

it('ofrece registrar desde un documento solo lo que los permisos dejan', function () {
    configureOpenAi();
    $f = contiLicensed();
    ['user' => $partners] = contiUser(['business_partners.partners' => 'read_write', 'accounting.journal_entries' => 'read'], $f['company']);

    $all = $this->actingAs($f['user'])->getJson(route('conti.scan.actions'))->assertOk()->json('acciones');
    expect(array_column($all, 'accion'))->toBe(['crear_asiento', 'crear_orden_compra', 'crear_socio', 'aplicar_pago']);

    // Los dos que son de la pantalla de socios.
    $mine = $this->actingAs($partners)->getJson(route('conti.scan.actions'))->assertOk()->json('acciones');
    expect(array_column($mine, 'accion'))->toBe(['crear_socio', 'aplicar_pago']);

    $this->actingAs($partners)->postJson(route('conti.scan.start'), ['accion' => 'crear_asiento'])->assertForbidden();
    $this->actingAs($partners)->postJson(route('conti.scan.start'), ['accion' => 'actualizar_precios'])->assertStatus(422);
});

it('el QR apunta a la IP de la red local y lleva un pase que solo sirve para ese escaneo', function () {
    $f = scanFixture();
    config(['conti.scanner.ip' => '192.168.18.14', 'app.url' => 'http://localhost:8000']);

    $scan = $this->actingAs($f['user'])->postJson(route('conti.scan.start'), ['accion' => 'crear_orden_compra'])->assertCreated()->json();

    expect($scan['url'])->toStartWith('http://192.168.18.14:8000/conti/escanear/')
        ->and($scan['minutos'])->toBe(10);

    $token = basename($scan['url']);
    expect(app(ContiScanService::class)->forToken($token))->not->toBeNull()
        ->and(app(ContiScanService::class)->forToken($token.'x'))->toBeNull();

    // La página del teléfono: sin sesión, solo para las fotos.
    auth()->logout();
    $this->get($scan['url'])->assertOk()->assertSee('Escanear')->assertSee('Tomar foto');
    $this->get(route('conti.phone.show', 'no-existe'))->assertOk()->assertSee('Este código ya no sirve');
});

it('el teléfono sube las fotos, la IA las lee y la computadora recibe el formulario; las fotos no se guardan', function () {
    Storage::fake('local');
    $f = scanFixture();
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response(scannedPurchaseOrder())]);

    $scan = $this->actingAs($f['user'])->postJson(route('conti.scan.start'), ['accion' => 'crear_orden_compra', 'sesion' => 'abc'])->json();
    $this->actingAs($f['user'])->getJson(route('conti.scan.status', $scan['id']))->assertJsonPath('estado', 'esperando');

    // Desde el teléfono, sin sesión.
    auth()->logout();
    $upload = route('conti.phone.upload', basename($scan['url']));
    $this->postJson($upload, ['fotos' => [photo()]])->assertOk()->assertJsonPath('message', 'Listo: revisalo en la computadora.');
    // Un solo uso.
    $this->postJson($upload, ['fotos' => [photo()]])->assertStatus(410);

    $form = $this->actingAs($f['user'])->getJson(route('conti.scan.status', $scan['id']))
        ->assertOk()
        ->assertJsonPath('estado', 'listo')
        ->json('formulario');

    // El proveedor, por su cédula jurídica; el artículo, por su nombre.
    expect($form['accion'])->toBe('crear_orden_compra')
        ->and($form['valores']['proveedor'])->toBe('PRV-001')
        ->and($form['valores']['fecha'])->toBe('2026-10-05')
        ->and($form['valores']['lineas'][0]['articulo'])->toBe('ART-1')
        ->and($form['valores']['lineas'][0]['cantidad'])->toBe('10')
        ->and($form['leidos'])->toHaveKey('proveedor')
        ->and($form['nota'][0])->toContain('Lo leí del documento');

    // Se entrega una sola vez.
    $this->actingAs($f['user'])->getJson(route('conti.scan.status', $scan['id']))->assertJsonPath('estado', 'vencido');

    Http::assertSent(fn (Request $request) => $request['response_format']['type'] === 'json_schema'
        && str_starts_with($request['messages'][1]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'));

    expect(ContiUsage::where('user_id', $f['user']->id)->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('en el teléfono, las fotos se leen ahí mismo; lo que no encuentra queda vacío con una nota', function () {
    $f = scanFixture();
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response(scannedPurchaseOrder([
        'proveedor' => 'Proveedor que no existe',
        'documento' => ['tipo' => null, 'numero' => null, 'fecha' => null, 'emisor' => null, 'emisor_identificacion' => null, 'receptor' => null, 'moneda' => null, 'subtotal' => null, 'impuesto' => null, 'total' => null],
    ]))]);

    $form = $this->actingAs($f['user'])->post(route('conti.scan.read'), ['accion' => 'crear_orden_compra', 'fotos' => [photo()]], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('formulario');

    expect($form['valores']['proveedor'])->toBe('')
        ->and(collect($form['nota'])->implode(' '))->toContain('Proveedor que no existe')
        ->and($form['valores']['lineas'][0]['articulo'])->toBe('ART-1');
});

it('nadie más ve un escaneo, y sin permiso, sin cupo o sin fotos no se lee', function () {
    $f = scanFixture();
    Http::fake();

    $scan = $this->actingAs($f['user'])->postJson(route('conti.scan.start'), ['accion' => 'crear_orden_compra'])->json();
    ['user' => $other] = contiUser(['inventory.purchase_orders' => 'read_write'], $f['company']);
    $this->actingAs($other)->getJson(route('conti.scan.status', $scan['id']))->assertJsonPath('estado', 'vencido');

    auth()->logout();
    $upload = route('conti.phone.upload', basename($scan['url']));
    $this->postJson($upload, ['fotos' => [UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fotos.0']);
    $this->postJson($upload, [])->assertStatus(422);

    // Sin cupo: no se llama al modelo, y la computadora se entera.
    $f['license']->update(['ai_daily_credits' => 1]);
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $f['user']->id, 'model' => 'gpt-4.1-mini', 'credits' => 2, 'cost_usd' => 0.02]);
    $this->postJson($upload, ['fotos' => [photo()]])->assertStatus(429);
    Http::assertNothingSent();

    $this->actingAs($f['user'])->getJson(route('conti.scan.status', $scan['id']))
        ->assertJsonPath('estado', 'esperando')
        ->assertJsonPath('mensaje', fn ($message) => str_contains($message, 'límite diario'));
});

it('el pase se lee con los permisos de ese momento: si se los quitaron, no lee', function () {
    $f = scanFixture();
    Http::fake();
    ['user' => $buyer] = contiUser(['inventory.purchase_orders' => 'read_write', 'business_partners.partners' => 'read', 'inventory.items' => 'read'], $f['company']);

    $scan = $this->actingAs($buyer)->postJson(route('conti.scan.start'), ['accion' => 'crear_orden_compra'])->assertCreated()->json();
    app(PermissionGrantService::class)->writeScreenLevels($f['company']->id, $buyer, ['inventory.purchase_orders' => 'read']);
    app()->forgetInstance(ContiContext::class);

    auth()->logout();
    $this->postJson(route('conti.phone.upload', basename($scan['url'])), ['fotos' => [photo()]])->assertForbidden();
    Http::assertNothingSent();
});
