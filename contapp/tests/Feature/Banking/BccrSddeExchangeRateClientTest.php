<?php

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Exceptions\BccrUnavailableException;
use App\Domains\Banking\Services\BccrSddeExchangeRateClient;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| El tipo de cambio del BCCR, desde su API de indicadores (SDDE)
|--------------------------------------------------------------------------
|
| GET …/indicadoresEconomicos/318/series con «Authorization: Bearer», el 318
| es el de referencia de venta. Lo que no se puede consultar se dice con su
| motivo (BccrUnavailableException); una fecha sin publicar es null.
|
*/

const BCCR_SERIES_URL = 'https://apim.bccr.fi.cr/SDDE/api/Bccr.GE.SDDE.Publico.Indicadores.API/indicadoresEconomicos/318/series*';

// Con la forma de los que genera el BCCR (un JWT).
const BCCR_TEST_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJwcnVlYmEifQ.firma-de-prueba';

beforeEach(fn () => config(['services.bccr.token' => BCCR_TEST_TOKEN]));

/** Lo que responde el BCCR, con la forma de su documentación. */
function bccrSeries(array $series): array
{
    return [
        'estado' => true,
        'mensaje' => 'Consulta exitosa',
        'datos' => [['codigoIndicador' => '318', 'nombreIndicador' => 'Tipo cambio venta', 'series' => $series]],
    ];
}

function bccrFetch(string $day = '2026-10-14', string $currency = 'USD'): ?string
{
    return app(BccrSddeExchangeRateClient::class)->fetchRate($currency, new DateTime($day));
}

it('pide el 318 (venta) de ese día con el token, y devuelve la tasa', function () {
    Http::fake([BCCR_SERIES_URL => Http::response(bccrSeries([['fecha' => '2026-10-14', 'valorDatoPorPeriodo' => 507.32]]))]);

    expect(bccrFetch())->toBe('507.320000');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.BCCR_TEST_TOKEN)
        && $request->method() === 'GET'
        && str_contains($request->url(), '/indicadoresEconomicos/318/series')
        && $request['fechaInicio'] === '2026/10/14'
        && $request['fechaFin'] === '2026/10/14'
        && $request['idioma'] === 'ES');
});

it('una fecha que todavía no está publicada es null, y cada fecha se pregunta una vez', function () {
    Http::fake([BCCR_SERIES_URL => Http::sequence()
        ->push(bccrSeries([]))
        ->push(bccrSeries([['fecha' => '2026-10-16T00:00:00', 'valorDatoPorPeriodo' => null]]))]);

    $client = app(BccrSddeExchangeRateClient::class);

    expect($client->fetchRate('USD', new DateTime('2026-10-15')))->toBeNull()
        ->and($client->fetchRate('USD', new DateTime('2026-10-15')))->toBeNull()
        ->and($client->fetchRate('USD', new DateTime('2026-10-16')))->toBeNull();

    Http::assertSentCount(2);
});

it('de otra moneda no pregunta: el BCCR publica la del dólar', function () {
    Http::fake();

    expect(bccrFetch(currency: 'EUR'))->toBeNull();

    Http::assertNothingSent();
});

it('sin token dice qué falta, sin llamar al BCCR', function () {
    config(['services.bccr.token' => null]);
    Http::fake();

    expect(fn () => bccrFetch())->toThrow(BccrUnavailableException::class, 'Falta el token del BCCR (BCCR_TOKEN)');

    Http::assertNothingSent();
});

it('cada error del BCCR se dice con su motivo', function (int $status, string $message) {
    Http::fake([BCCR_SERIES_URL => Http::response(['CodigoError' => (string) $status, 'Mensaje' => 'Error'], $status)]);

    expect(fn () => bccrFetch())->toThrow(BccrUnavailableException::class, $message);
})->with([
    'token vencido' => [401, 'El BCCR rechazó el token (BCCR_TOKEN): venció o no es válido.'],
    'sin suscripción' => [403, 'la suscripción de ese token no está activa'],
    'demasiadas consultas' => [429, 'demasiadas consultas'],
    'caído' => [500, 'El BCCR no respondió bien (código 500)'],
]);

it('un token copiado a medias (el BCCR responde 500) se dice como tal', function () {
    config(['services.bccr.token' => 'eyJhbGciOiJIUzI1Ni']);
    Http::fake([BCCR_SERIES_URL => Http::response(['statusCode' => 500, 'message' => 'Internal server error'], 500)]);

    expect(fn () => bccrFetch())->toThrow(BccrUnavailableException::class, 'no tiene la forma de los que genera el BCCR');
});

it('sin conexión, lo reintenta y después lo dice', function () {
    Sleep::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

    expect(fn () => bccrFetch())->toThrow(BccrUnavailableException::class, 'No se pudo conectar con el BCCR.');
});

it('una respuesta con estado false trae el mensaje del BCCR', function () {
    Http::fake([BCCR_SERIES_URL => Http::response(['estado' => false, 'mensaje' => 'Parámetros de entrada inválidos.', 'datos' => []])]);

    expect(fn () => bccrFetch())->toThrow(BccrUnavailableException::class, 'El BCCR respondió: «Parámetros de entrada inválidos.».');
});

// ── De punta a punta: la pantalla y el comando ──────────────────────────

it('«Sincronizar con BCCR» guarda la tasa del día como referencia, del BCCR', function () {
    Http::fake([BCCR_SERIES_URL => Http::response(bccrSeries([['fecha' => '2026-10-14', 'valorDatoPorPeriodo' => 507.32]]))]);
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('exchange-rates.sync'), ['date' => '2026-10-14'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Tipo de cambio del BCCR del 14/10/2026: 507.320000.');

    $rate = ExchangeRate::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->sole();
    expect($rate->only(['rate_type', 'source']))->toBe(['rate_type' => 'reference', 'source' => 'bccr_api'])
        ->and((string) $rate->rate)->toEqual('507.320000');
});

it('la pantalla dice por qué no sincronizó, y sin token ni ofrece el botón', function () {
    ['company' => $company] = logInAsCompanyUser();
    Http::fake([BCCR_SERIES_URL => Http::sequence()
        ->push(['CodigoError' => '401'], 401)
        ->push(bccrSeries([]))]);

    $this->post(route('exchange-rates.sync'), ['date' => '2026-10-14'])
        ->assertSessionHasErrors(['sync' => 'La conexión de Pivox con el BCCR no está funcionando. Avisale al equipo de Pivox. Mientras tanto, se puede cargar a mano con «Crear nuevo».']);

    $this->post(route('exchange-rates.sync'), ['date' => '2026-10-20'])
        ->assertSessionHasErrors(['sync' => 'El BCCR todavía no tiene el tipo de cambio del 20/10/2026: lo publica la tarde del día anterior. Para una fecha más adelante, cargalo a mano con «Crear nuevo».']);

    expect(ExchangeRate::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->count())->toBe(0);

    $this->get(route('exchange-rates.index'))->assertInertia(fn ($page) => $page->where('bccrConfigured', true));
    config(['services.bccr.token' => '']);
    $this->get(route('exchange-rates.index'))->assertInertia(fn ($page) => $page->where('bccrConfigured', false));
});

it('una compañía con otra moneda extranjera no le pregunta al BCCR', function () {
    Http::fake();
    ['company' => $company] = logInAsCompanyUser();
    $eur = Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€']);
    $company->update(['foreign_currency_id' => $eur->id]);

    $this->post(route('exchange-rates.sync'), ['date' => '2026-10-14'])
        ->assertSessionHasErrors(['sync' => 'El BCCR publica el tipo de cambio del dólar; el de EUR se carga a mano con «Crear nuevo».']);

    Http::assertNothingSent();
});

it('el comando sincroniza todas las compañías con una sola consulta, y si el BCCR falla lo dice', function () {
    Http::fake([BCCR_SERIES_URL => Http::sequence()
        ->push(bccrSeries([['fecha' => '2026-10-14', 'valorDatoPorPeriodo' => 507.32]]))
        ->push(['CodigoError' => '401'], 401)]);
    logInAsCompanyUser();
    logInAsCompanyUser();

    $this->artisan('pivox:sync-bccr-rates', ['--date' => '2026-10-14', '--catch-up' => 0])->assertSuccessful();

    expect(ExchangeRate::withoutGlobalScope(CompanyScope::class)->where('source', 'bccr_api')->count())->toBe(2);
    Http::assertSentCount(1);

    $this->artisan('pivox:sync-bccr-rates', ['--date' => '2026-10-15', '--catch-up' => 0])
        ->expectsOutputToContain('El BCCR rechazó el token')
        ->assertFailed();
});
