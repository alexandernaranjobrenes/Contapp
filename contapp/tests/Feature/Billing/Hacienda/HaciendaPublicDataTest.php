<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\Hacienda\HaciendaPublicData;
use App\Domains\Billing\Support\CabysCatalog;
use App\Domains\Billing\Support\CostaRicaTerritory;
use App\Domains\Billing\Support\EconomicActivityCatalog;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\UnitOfMeasure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| Los datos de Hacienda que el comprobante tiene que respetar, y que antes no
| se revisaban (rechazos -400, -408 y -111 del primer comprobante de prueba):
|
| - la división territorial oficial (Codificacionubicacion_V4.4),
| - el catálogo CAByS y las actividades inscritas (api.hacienda.go.cr),
| - servicio o mercancía según el CAByS, como lo cruza Hacienda.
|
| El API público se simula con Http::fake; ninguna prueba sale a la red.
*/

beforeEach(function () {
    Queue::fake();
});

function enablePublicApi(array $fakes): void
{
    config(['hacienda.public_api.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake($fakes);
}

function cabysRow(string $code, string $description, int $tax = 13): array
{
    return ['codigo' => $code, 'descripcion' => $description, 'impuesto' => $tax, 'categorias' => ['Categoría'], 'uri' => ''];
}

/** La respuesta de /fe/ae para la cédula de salesFixture(). */
function taxpayerResponse(array $activities): array
{
    return [
        'nombre' => 'EMPRESA DE PRUEBA SOCIEDAD ANONIMA',
        'tipoIdentificacion' => '02',
        'regimen' => ['codigo' => 1, 'descripcion' => 'Régimen general'],
        'situacion' => ['moroso' => 'NO', 'omiso' => 'NO', 'estado' => 'Inscrito', 'administracionTributaria' => 'San José'],
        'actividades' => array_map(fn ($code) => ['estado' => 'A', 'tipo' => 'P', 'codigo' => $code, 'descripcion' => "Actividad {$code}"], $activities),
    ];
}

// --- División territorial ---

it('la ubicación sale de la tabla oficial de Hacienda: 7 provincias, 84 cantones, 492 distritos', function () {
    $tree = CostaRicaTerritory::tree();

    expect($tree)->toHaveCount(7)
        ->and(array_sum(array_map(fn ($p) => count($p['cantons']), $tree)))->toBe(84)
        ->and(array_sum(array_map(fn ($p) => array_sum(array_map(fn ($c) => count($c['districts']), $p['cantons'])), $tree)))->toBe(492)
        ->and(CostaRicaTerritory::names('3', '01', '04'))->toBe(['Cartago', 'Cartago', 'San Nicolás'])
        ->and(CostaRicaTerritory::names('1', '01', '08'))->toBe(['San José', 'San José', 'Mata Redonda'])
        ->and(CostaRicaTerritory::exists('3', '09'))->toBeFalse();
});

it('el emisor no se guarda con un cantón que no es de su provincia ni un distrito que no es de su cantón', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $company->update(['tax_id' => '3101123456']);

    $payload = [
        'identification_type' => '02',
        'address_details' => '100 metros norte del parque',
        'emails' => ['facturacion@empresa.cr'],
        'branch' => '001', 'terminal' => '00001',
    ];

    // Cartago tiene ocho cantones.
    $this->put(route('hacienda-settings.emitter'), [...$payload, 'province' => '3', 'canton' => '09', 'district' => '01'])
        ->assertSessionHasErrors(['canton' => 'Ese cantón no es de Cartago.']);

    // El cantón Cartago tiene once distritos.
    $this->put(route('hacienda-settings.emitter'), [...$payload, 'province' => '3', 'canton' => '01', 'district' => '12'])
        ->assertSessionHasErrors(['district' => 'Ese distrito no es de Cartago.']);

    $this->put(route('hacienda-settings.emitter'), [...$payload, 'province' => '3', 'canton' => '01', 'district' => '04'])
        ->assertSessionHasNoErrors();

    expect(HaciendaSetting::first()->only(['province', 'canton', 'district']))->toBe(['province' => '3', 'canton' => '01', 'district' => '04']);
});

it('la pantalla del emisor recibe la tabla para llenar cantón y distrito por nombre', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->get(route('hacienda-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('catalogs.territory.3.name', 'Cartago')
            ->where('catalogs.territory.3.cantons.01.districts.04', 'San Nicolás'));
});

it('la ubicación del socio es opcional, pero si viene tiene que ser coherente', function () {
    ['company' => $company] = logInAsCompanyUser();
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $base = [
        'code' => 'C-200', 'name' => 'Cliente con ubicación', 'type' => 'client',
        'gl_account_id' => $account->id, 'currency_id' => $company->local_currency_id,
    ];

    // Un cantón sin provincia no dice dónde es.
    $this->post(route('business-partners.store'), [...$base, 'canton' => '01'])->assertSessionHasErrors('province');

    // Heredia no tiene cantón 11.
    $this->post(route('business-partners.store'), [...$base, 'province' => '4', 'canton' => '11'])->assertSessionHasErrors('canton');

    $this->post(route('business-partners.store'), [...$base, 'province' => '4', 'canton' => '01', 'district' => '02'])
        ->assertSessionHasNoErrors();

    expect(BusinessPartner::where('code', 'C-200')->sole()->only(['province', 'canton', 'district']))
        ->toBe(['province' => '4', 'canton' => '01', 'district' => '02']);
});

// --- Servicio o mercancía: lo decide el CAByS (rechazo -111) ---

it('Hacienda clasifica por el CAByS: una línea con CAByS de servicio va a servicios aunque el ERP la trate como mercancía', function () {
    $f = salesFixture();

    // Sale de bodega (is_service = false) pero su CAByS es de la sección 8.
    $document = postSale($f, ['lines' => [saleLine($f, 2, 600, ['cabys' => TEST_CABYS_SERVICE])]]);

    expect((float) $document->total_taxed_services)->toBe(1200.0)
        ->and((float) $document->total_taxed_goods)->toBe(0.0);
});

it('y una línea de servicio con CAByS de bienes va a mercancías', function () {
    $f = salesFixture();

    $document = postSale($f, ['lines' => [
        saleLine($f, 1, 5000, ['isService' => true, 'itemId' => null, 'warehouseId' => null, 'cabys' => TEST_CABYS_GOODS,
            'taxes' => [new SalesTaxInput(ivaRateCode: '10')]]),
    ]]);

    expect((float) $document->total_exempt_goods)->toBe(5000.0)
        ->and((float) $document->total_exempt_services)->toBe(0.0);
});

// --- El catálogo CAByS oficial (BCCR) ---

it('el catálogo CAByS es el oficial completo, y se busca por código o por palabras sin importar tildes', function () {
    expect(CabysCatalog::count())->toBe(20506)
        ->and(CabysCatalog::search('')['total'])->toBe(20506)
        ->and(CabysCatalog::search('8314')['total'])->toBe(11)
        ->and(CabysCatalog::find('4526100000100'))->toMatchArray([
            'description' => 'Teclados para computadoras', 'tax' => '13', 'is_service' => false,
            'section' => 'Productos metálicos, maquinaria y equipo',
        ])
        ->and(CabysCatalog::find('3229900009900')['tax'])->toBe('exento')
        ->and(CabysCatalog::find('8471600000000'))->toBeNull();

    // «cafe» encuentra «Café»; todas las palabras tienen que estar.
    $cafe = array_column(CabysCatalog::search('cafe molido')['results'], 'tax', 'code');
    expect($cafe['2391102010200'] ?? null)->toBe('1');
});

it('la lista de CAByS se pide de a páginas, filtrada o completa', function () {
    logInAsCompanyUser();

    $this->getJson(route('hacienda.cabys'))
        ->assertOk()
        ->assertJsonPath('total', 20506)
        ->assertJsonCount(100, 'results')
        ->assertJsonPath('results.0.section', 'Productos de la agricultura, silvicultura y pesca');

    $this->getJson(route('hacienda.cabys', ['offset' => 20500]))->assertJsonCount(6, 'results');

    $this->getJson(route('hacienda.cabys', ['q' => 'teclados computadoras']))
        ->assertJsonPath('results.0.code', '4526100000100');
});

it('un código del catálogo se reconoce sin preguntarle a Hacienda', function () {
    enablePublicApi([]);

    expect(app(HaciendaPublicData::class)->cabys(TEST_CABYS_SERVICE))->toMatchArray(['exists' => true, 'is_service' => true]);

    Http::assertNothingSent();
});

// --- API público de Hacienda ---

it('distingue un CAByS que no existe de un API que no contesta', function () {
    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=4526100000100' => Http::response([cabysRow('4526100000100', 'Teclados para computadoras')]),
        'api.hacienda.go.cr/fe/cabys?codigo=8471600000000' => Http::response([]),
        'api.hacienda.go.cr/fe/cabys?codigo=1111111111111' => Http::response('', 503),
    ]);

    $hacienda = app(HaciendaPublicData::class);

    expect($hacienda->cabys('4526100000100'))->toMatchArray(['exists' => true, 'description' => 'Teclados para computadoras'])
        ->and($hacienda->cabys('8471600000000'))->toBe(['exists' => false])
        ->and($hacienda->cabys('1111111111111'))->toBeNull();
});

it('con el API apagado nada se consulta y nada se bloquea', function () {
    Http::preventStrayRequests();

    expect(app(HaciendaPublicData::class)->cabys('8471600000000'))->toBeNull()
        ->and(app(HaciendaPublicData::class)->taxpayer('3101123456'))->toBeNull();
});

it('trae las actividades inscritas de una cédula, y dice cuando Hacienda no la tiene', function () {
    enablePublicApi([
        'api.hacienda.go.cr/fe/ae?identificacion=3101123456' => Http::response(taxpayerResponse(['6209.0'])),
        'api.hacienda.go.cr/fe/ae?identificacion=999999999' => Http::response(['code' => 404, 'status' => 'Information no available'], 404),
    ]);

    $hacienda = app(HaciendaPublicData::class);

    expect($hacienda->taxpayer('3-101-123456'))->toMatchArray(['found' => true, 'identification_type' => '02', 'status' => 'Inscrito'])
        ->and($hacienda->taxpayer('3101123456')['activities'][0])->toBe(['code' => '6209.0', 'description' => 'Actividad 6209.0', 'active' => true, 'primary' => true])
        ->and($hacienda->activityIsRegistered('3101123456', '6209.0'))->toBeTrue()
        ->and($hacienda->activityIsRegistered('3101123456', '6209.9'))->toBeFalse()
        ->and($hacienda->taxpayer('999999999'))->toBe(['found' => false]);
});

it('las pantallas consultan el catálogo y la cédula por JSON', function () {
    logInAsCompanyUser();
    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=8471600000000' => Http::response([]),
        'api.hacienda.go.cr/fe/ae?identificacion=3101123456' => Http::response(taxpayerResponse(['6209.0'])),
    ]);

    $this->getJson(route('hacienda.cabys', ['q' => 'teclado computadora']))
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('results.0.code', '4526100000100');

    $this->getJson(route('hacienda.cabys', ['code' => '8471600000000']))
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('exists', false);

    $this->getJson(route('hacienda.taxpayer', ['identification' => '3101123456']))
        ->assertOk()
        ->assertJsonPath('taxpayer.activities.0.code', '6209.0');
});

// --- Actividades económicas: el catálogo de TRIBU-CR ---

it('el catálogo de actividades es la tabla oficial de Hacienda (CIIU 4)', function () {
    $catalog = EconomicActivityCatalog::all();

    expect($catalog)->toHaveCount(367)
        ->and($catalog['6209.0'])->toBe('Otras actividades de la tecnología de información y servicio informáticos')
        ->and($catalog['6201.0'])->toBe('Actividades de programación informática')
        ->and(EconomicActivityCatalog::exists('6209.9'))->toBeFalse();
});

it('Parámetros de facturación recibe el catálogo para elegir código y nombre', function () {
    logInAsCompanyUser();

    $this->get(route('billing-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activityCatalog', fn ($catalog) => $catalog->count() === 367
                && $catalog['6209.0'] === 'Otras actividades de la tecnología de información y servicio informáticos'));
});

it('una actividad con el formato de la v4.4 tiene que ser de Hacienda', function () {
    ['company' => $company] = logInAsCompanyUser();
    $company->update(['tax_id' => '3101123456']);
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id]);
    $payload = fn (string $code) => ['code' => $code, 'name' => 'Actividad', 'revenue_account_id' => $account->id];

    // 6209.9 no existe en TRIBU-CR (era la del primer rechazo, -408).
    $this->post(route('billing-settings.activities.store'), $payload('6209.9'))
        ->assertSessionHasErrors(['code' => 'La actividad 6209.9 no está en el catálogo de actividades de Hacienda.']);

    $this->post(route('billing-settings.activities.store'), $payload('6209.0'))->assertSessionHasNoErrors();

    // Una que no está en la tabla publicada, pero Hacienda dice que la cédula la tiene inscrita.
    enablePublicApi(['api.hacienda.go.cr/fe/ae*' => Http::response(taxpayerResponse(['9999.1']))]);
    $this->post(route('billing-settings.activities.store'), $payload('9999.1'))->assertSessionHasNoErrors();

    expect(CompanyEconomicActivity::where('company_id', $company->id)->pluck('code')->sort()->values()->all())
        ->toBe(['6209.0', '9999.1']);
});

// --- La ficha del artículo ---

it('la ficha del artículo no guarda un CAByS que Hacienda dice que no existe', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=8471600000000' => Http::response([]),
        'api.hacienda.go.cr/fe/cabys?codigo=4526100000100' => Http::response([cabysRow('4526100000100', 'Teclados para computadoras')]),
    ]);

    $payload = ['code' => 'ART-T', 'name' => 'Teclado', 'uom_id' => $uom->id, 'is_inventory_item' => true, 'is_sales_item' => true, 'status' => 'active'];

    $this->post(route('items.store'), [...$payload, 'cabys_code' => '8471600000000'])->assertSessionHasErrors('cabys_code');
    $this->post(route('items.store'), [...$payload, 'cabys_code' => '4526100000100'])->assertSessionHasNoErrors();

    expect(Item::where('company_id', $company->id)->sole()->cabys_code)->toBe('4526100000100');
});

it('si Hacienda no contesta, el CAByS de la ficha se guarda igual', function () {
    ['company' => $company] = logInAsCompanyUser();
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    enablePublicApi(['api.hacienda.go.cr/*' => Http::response('', 503)]);

    $this->post(route('items.store'), [
        'code' => 'ART-T', 'name' => 'Teclado', 'uom_id' => $uom->id, 'is_inventory_item' => true,
        'is_sales_item' => true, 'status' => 'active', 'cabys_code' => '4526100000100',
    ])->assertSessionHasNoErrors();
});

// --- Antes de emitir: lo que Hacienda ya dijo que rechaza ---

function preflightFixture(): array
{
    $f = salesFixture();
    $f['company']->update(['tax_id' => '3101123456']);
    logInAsCompanyUser($f['company']);

    $f['salesType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE-P', 'origin_module' => 'ventas',
    ]);

    return enableHacienda($f);
}

function preflightPayload(array $f, string $cabys): array
{
    return [
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
            'cabys_code' => $cabys,
            'description' => 'Teclado inalámbrico',
            'unit_code' => 'Unid',
            'quantity' => 1,
            'unit_price' => 1200,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'item_code' => 'ART-1',
            'is_service' => false,
            'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
        ]],
    ];
}

it('no emite con un CAByS que no está en el catálogo: no gasta el consecutivo en un -400', function () {
    $f = preflightFixture();

    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=8471600000000' => Http::response([]),
        'api.hacienda.go.cr/fe/ae*' => Http::response(taxpayerResponse(['6201.0'])),
    ]);

    $this->post(route('sales-documents.store'), preflightPayload($f, '8471600000000'))
        ->assertSessionHasErrors(['billing' => 'La línea 1 lleva el CAByS 8471600000000, que no está en el catálogo de Hacienda (lo rechaza con -400). Corregilo en la ficha del artículo o en el detalle de la línea.']);

    expect(SalesDocument::where('company_id', $f['company']->id)->count())->toBe(0);
});

it('no emite con una actividad que el emisor no tiene inscrita: no gasta el consecutivo en un -408', function () {
    $f = preflightFixture(); // la actividad de la compañía es 6201.0

    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=4526100000100' => Http::response([cabysRow('4526100000100', 'Teclados para computadoras')]),
        'api.hacienda.go.cr/fe/ae*' => Http::response(taxpayerResponse(['6209.0'])),
    ]);

    $response = $this->post(route('sales-documents.store'), preflightPayload($f, '4526100000100'));

    $response->assertSessionHasErrors('billing');
    expect(session('errors')->first('billing'))->toContain('6201.0 no está inscrita')->toContain('6209.0');
    expect(SalesDocument::where('company_id', $f['company']->id)->count())->toBe(0);
});

it('«Emitir de nuevo» no gasta otro consecutivo si el CAByS guardado en la venta es el que Hacienda rechazó', function () {
    $f = preflightFixture();
    $document = electronicSale($f, ['lines' => [saleLine($f, 1, 1200, ['cabys' => '8471600000000'])]]);
    $electronic = electronicOf($document);
    $electronic->update(['status' => ElectronicDocument::REJECTED]);

    enablePublicApi([
        'api.hacienda.go.cr/fe/cabys?codigo=8471600000000' => Http::response([]),
        'api.hacienda.go.cr/fe/ae*' => Http::response(taxpayerResponse(['6201.0'])),
    ]);

    $this->post(route('sales-documents.hacienda.reissue', $document->id))
        ->assertSessionHas('error', 'No se emitió de nuevo: La línea 1 lleva el CAByS 8471600000000, que no está en el catálogo de Hacienda (lo rechaza con -400). Quedó guardado en la venta: emitirla de nuevo no lo cambia.');

    expect(ElectronicDocument::where('sales_document_id', $document->id)->count())->toBe(1)
        ->and($electronic->fresh()->status)->toBe(ElectronicDocument::REJECTED)
        ->and($document->fresh()->consecutive)->toBe($document->consecutive);
});

it('la actividad del receptor se elige del catálogo de Hacienda y llega al XML', function () {
    $f = preflightFixture();

    $this->get(route('sales-documents.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activityCatalog', fn ($catalog) => $catalog->count() === 367));

    // 6209.9 no existe en TRIBU-CR.
    $this->post(route('sales-documents.store'), [...preflightPayload($f, '4526100000100'), 'receiver_activity_code' => '6209.9'])
        ->assertSessionHasErrors('receiver_activity_code');
    expect(SalesDocument::where('company_id', $f['company']->id)->count())->toBe(0);

    $this->post(route('sales-documents.store'), [...preflightPayload($f, '4526100000100'), 'receiver_activity_code' => '6209.0'])
        ->assertSessionHasNoErrors();

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();
    expect($document->receiver_activity_code)->toBe('6209.0')
        ->and(haciendaValue(electronicOf($document)->signed_xml, '//fe:CodigoActividadReceptor'))->toBe('6209.0');
});

it('«sin actividad del receptor» es una elección: no se toma sola de la ficha del socio', function () {
    $f = preflightFixture();
    $f['customer']->update(['economic_activity_code' => '620100']);

    $this->post(route('sales-documents.store'), preflightPayload($f, '4526100000100'))->assertSessionHasNoErrors();

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();
    expect($document->receiver_activity_code)->toBeNull()
        ->and(haciendaXpath(electronicOf($document)->signed_xml)->query('//fe:CodigoActividadReceptor')->length)->toBe(0);
});

it('con el CAByS y la actividad en regla, o con Hacienda sin contestar, emite', function () {
    $f = preflightFixture();

    enablePublicApi(['api.hacienda.go.cr/*' => Http::response('', 503)]);

    $this->post(route('sales-documents.store'), preflightPayload($f, '4526100000100'))->assertSessionHasNoErrors();

    expect(SalesDocument::where('company_id', $f['company']->id)->count())->toBe(1);
});
