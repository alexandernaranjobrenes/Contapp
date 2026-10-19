<?php

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| Administración → Facturación electrónica, y las acciones de un comprobante
| ante Hacienda desde su pantalla.
|
| Las credenciales las aporta SOLO el Superusuario de cada compañía: se
| prueba que un Administrador no puede ni verlas, que se guardan cifradas y
| que ninguna viaja a la pantalla.
*/

beforeEach(function () {
    Queue::fake();
});

/** salesFixture() con la sesión iniciada: el Superusuario, o un Administrador. */
function haciendaHttpFixture(bool $superuser = true): array
{
    $f = salesFixture();

    ['user' => $user] = logInAsCompanyUser($f['company'], ['is_super_admin' => $superuser]);

    $f['user'] = $user;
    $f['salesType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE-E', 'origin_module' => 'ventas',
    ]);

    return $f;
}

// --- Solo el Superusuario ---

it('el Superusuario ve la pantalla, con lo que falta para emitir', function () {
    haciendaHttpFixture();

    $this->get(route('hacienda-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Hacienda/Settings')
            ->where('settings.enabled', false)
            ->has('missing.stag')
            ->has('missing.prod')
            ->has('series', 5));
});

it('un Administrador no ve ni cambia nada de la facturación electrónica', function () {
    haciendaHttpFixture(superuser: false);

    $this->get(route('hacienda-settings.index'))->assertForbidden();
    $this->put(route('hacienda-settings.credentials', 'stag'), ['username' => 'x', 'password' => 'y'])->assertForbidden();
    $this->post(route('hacienda-settings.enable'))->assertForbidden();

    expect(HaciendaCredential::count())->toBe(0);
});

// --- Credenciales: cifradas, y nunca de vuelta a la pantalla ---

it('guarda la contraseña del ATV cifrada y no la devuelve', function () {
    $f = haciendaHttpFixture();

    $this->put(route('hacienda-settings.credentials', 'stag'), [
        'username' => 'cpj-3-101-123456@stag.comprobanteselectronicos.go.cr',
        'password' => 'MiClaveSecreta-123',
    ])->assertSessionHasNoErrors();

    $raw = DB::table('hacienda_credentials')->where('company_id', $f['company']->id)->first();

    expect($raw->password)->not->toContain('MiClaveSecreta')
        ->and(HaciendaCredential::first()->password)->toBe('MiClaveSecreta-123');

    $response = $this->get(route('hacienda-settings.index'));

    expect($response->getContent())->not->toContain('MiClaveSecreta')
        ->and($response->viewData('page')['props']['credentials']['stag']['has_password'])->toBeTrue();

    expect(AuditLog::where('action', 'company.hacienda_credentials_updated')->value('new_values'))
        ->not->toHaveKey('password')
        ->and(json_encode(AuditLog::where('action', 'company.hacienda_credentials_updated')->value('new_values')))->not->toContain('MiClaveSecreta');
});

it('dejar la contraseña vacía conserva la anterior', function () {
    haciendaHttpFixture();

    $this->put(route('hacienda-settings.credentials', 'stag'), ['username' => 'usuario', 'password' => 'primera']);
    $this->put(route('hacienda-settings.credentials', 'stag'), ['username' => 'usuario', 'password' => ''])->assertSessionHasNoErrors();

    expect(HaciendaCredential::first()->password)->toBe('primera');
});

it('carga la llave: la revisa con el PIN, la guarda cifrada y nunca en el disco', function () {
    $f = haciendaHttpFixture();

    $this->post(route('hacienda-settings.certificate', 'stag'), [
        'certificate' => UploadedFile::fake()->createWithContent('llave.p12', haciendaP12()),
        'pin' => '1234',
    ])->assertSessionHasNoErrors();

    $credential = HaciendaCredential::first();
    $raw = DB::table('hacienda_credentials')->where('id', $credential->id)->first();

    expect($credential->hasCertificate())->toBeTrue()
        ->and($credential->certificate_holder_id)->toBe('3101123456')
        ->and($raw->certificate_pin)->not->toBe('1234')
        ->and($raw->certificate)->not->toBe(base64_encode(haciendaP12()))
        ->and($this->get(route('hacienda-settings.index'))->getContent())->not->toContain('"certificate_pin"');
});

it('rechaza la llave al cargarla si el PIN no corresponde, no al facturar', function () {
    haciendaHttpFixture();

    $this->post(route('hacienda-settings.certificate', 'stag'), [
        'certificate' => UploadedFile::fake()->createWithContent('llave.p12', haciendaP12()),
        'pin' => '0000',
    ])->assertSessionHasErrors(['certificate' => 'El PIN no corresponde a la llave criptográfica.']);

    expect(HaciendaCredential::first()?->hasCertificate())->toBeFalsy();
});

it('rechaza la llave de otra compañía', function () {
    haciendaHttpFixture();

    $this->post(route('hacienda-settings.certificate', 'stag'), [
        'certificate' => UploadedFile::fake()->createWithContent('llave.p12', haciendaP12('CPJ-3-101-777777')),
        'pin' => '1234',
    ])->assertSessionHasErrors('certificate');
});

it('probar la conexión pide un token y no envía ningún comprobante', function () {
    $f = enableHacienda(haciendaHttpFixture());
    fakeHacienda();

    $this->post(route('hacienda-settings.test', 'stag'))->assertSessionHas('success');

    Http::assertSentCount(1);
    expect($f['credential']->fresh()->last_test_ok)->toBeTrue();
});

// --- Encender ---

it('no enciende mientras falte algo, y dice qué', function () {
    haciendaHttpFixture();

    $this->post(route('hacienda-settings.enable'))->assertSessionHas('error');

    expect(HaciendaSetting::first()?->enabled)->toBeFalsy();
});

it('enciende cuando todo está listo, y queda en la Bitácora', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $f['settings']->update(['enabled' => false]);

    $this->post(route('hacienda-settings.enable'))->assertSessionHas('success');

    expect($f['settings']->fresh()->enabled)->toBeTrue()
        ->and(AuditLog::where('action', 'company.hacienda_enabled')->exists())->toBeTrue();
});

it('no pasa a producción encendida sin las credenciales de producción', function () {
    $f = enableHacienda(haciendaHttpFixture());

    $this->put(route('hacienda-settings.environment'), ['environment' => 'prod'])->assertSessionHas('error');

    expect($f['settings']->fresh()->environment)->toBe('stag');
});

it('guarda los datos del emisor con el correo que exige el XSD', function () {
    haciendaHttpFixture();

    $this->put(route('hacienda-settings.emitter'), [
        'identification_type' => '02',
        'province' => '1',
        'canton' => '01',
        'district' => '08',
        'neighborhood' => 'Barrio Amón',
        'address_details' => '100 metros norte del parque',
        'phone' => '2222-3333',
        'emails' => ['facturacion@empresa.cr'],
        'branch' => '001',
        'terminal' => '00002',
        'send_to_customer' => true,
    ])->assertSessionHasNoErrors();

    expect(HaciendaSetting::first()->terminal)->toBe('00002')
        ->and(HaciendaSetting::first()->emailList())->toBe(['facturacion@empresa.cr']);
});

it('el tipo de identificación del emisor tiene que corresponder a su cédula', function () {
    haciendaHttpFixture();

    $this->put(route('hacienda-settings.emitter'), [
        'identification_type' => '01',
        'province' => '1', 'canton' => '01', 'district' => '08',
        'address_details' => '100 metros norte del parque',
        'emails' => ['facturacion@empresa.cr'],
        'branch' => '001', 'terminal' => '00001',
    ])->assertSessionHasErrors('identification_type');
});

it('fija el próximo consecutivo de una serie', function () {
    haciendaHttpFixture();

    $this->put(route('hacienda-settings.sequence'), [
        'branch' => '001', 'terminal' => '00001', 'document_type' => '01', 'next_number' => 2500,
    ])->assertSessionHasNoErrors();

    $this->get(route('hacienda-settings.index'))->assertInertia(fn ($page) => $page
        ->where('series.0.document_type', '01')
        ->where('series.0.next_number', 2500));
});

// --- El comprobante en pantalla ---

it('la pantalla del comprobante muestra su estado ante Hacienda, sin el XML', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $document = electronicSale($f);

    $this->get(route('sales-documents.show', $document->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('electronic.status', ElectronicDocument::SIGNED)
            ->where('electronic.clave', $document->clave)
            ->where('electronic.can_retry', true)
            ->missing('electronic.signed_xml'));

    $this->get(route('sales-documents.index'))
        ->assertInertia(fn ($page) => $page->where('documents.0.electronic_document.status', ElectronicDocument::SIGNED));
});

it('descarga el XML firmado tal como se envió', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $document = electronicSale($f);

    $response = $this->get(route('sales-documents.xml', $document->id))->assertOk();

    expect($response->streamedContent())->toBe(electronicOf($document)->signed_xml);
});

it('descarga el PDF del comprobante', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $document = electronicSale($f);

    $response = $this->get(route('sales-documents.pdf', $document->id))->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('consultar el estado desde la pantalla aplica lo que contesta Hacienda', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $document = electronicSale($f);
    $electronic = electronicOf($document);
    $electronic->update(['status' => ElectronicDocument::SUBMITTED]);

    fakeHacienda(['api-sandbox.comprobanteselectronicos.go.cr/*' => Http::response(haciendaStatus($electronic->clave, 'aceptado'))]);

    $this->post(route('sales-documents.hacienda.check', $document->id))->assertSessionHas('success');

    expect($electronic->fresh()->status)->toBe(ElectronicDocument::ACCEPTED);
});

it('volver a emitir un rechazado desde la pantalla', function () {
    $f = enableHacienda(haciendaHttpFixture());
    $document = electronicSale($f);
    electronicOf($document)->update(['status' => ElectronicDocument::REJECTED]);

    $this->post(route('sales-documents.hacienda.reissue', $document->id))->assertSessionHas('success');

    expect(ElectronicDocument::where('sales_document_id', $document->id)->count())->toBe(2)
        ->and(electronicOf($document)->status)->toBe(ElectronicDocument::SIGNED);
});

it('emitir por HTTP con la emisión encendida firma el comprobante', function () {
    $f = enableHacienda(haciendaHttpFixture());

    $this->post(route('sales-documents.store'), [
        'document_type_id' => $f['salesType']->id,
        'fiscal_document_type' => '01',
        'business_partner_id' => $f['customer']->id,
        'currency_id' => $f['company']->local_currency_id,
        'exchange_rate' => 1,
        'sale_condition' => '02',
        'credit_term_days' => 30,
        'document_date' => (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'cabys_code' => '4526100000100',
            'description' => 'Producto de prueba',
            'unit_code' => 'Unid',
            'quantity' => 10,
            'unit_price' => 2500,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'item_code' => 'ART-1',
            'is_service' => false,
            'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();

    expect(electronicOf($document)->status)->toBe(ElectronicDocument::SIGNED);
    expectValidAgainstXsd(electronicOf($document));
});
