<?php

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Support\BccrSyncStatus;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| El tipo de cambio del BCCR, solo, todos los días
|--------------------------------------------------------------------------
|
| El programador corre pivox:sync-bccr-rates dos veces al día en hora de
| Costa Rica (en Docker, el servicio `scheduler`). Trae el de hoy, completa
| los días anteriores que falten y deja constancia de cómo le fue.
|
*/

const BCCR_AUTO_URL = 'https://apim.bccr.fi.cr/SDDE/api/Bccr.GE.SDDE.Publico.Indicadores.API/indicadoresEconomicos/318/series*';

beforeEach(fn () => config(['services.bccr.token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJwcnVlYmEifQ.firma-de-prueba']));

/** El BCCR falso: el tipo de cambio de cada día es 500 más el día del mes. */
function bccrFakeByDay(): void
{
    Http::fake([BCCR_AUTO_URL => function (Request $request) {
        $day = str_replace('/', '-', $request['fechaInicio']);

        return Http::response([
            'estado' => true,
            'mensaje' => 'Consulta exitosa',
            'datos' => [['codigoIndicador' => '318', 'series' => [['fecha' => $day, 'valorDatoPorPeriodo' => 500 + (int) substr($day, -2)]]]],
        ]);
    }]);
}

function bccrRatesOf($company): array
{
    return ExchangeRate::withoutGlobalScope(CompanyScope::class)
        ->where('company_id', $company->id)
        ->orderBy('rate_date')
        ->get()
        ->mapWithKeys(fn (ExchangeRate $r) => [$r->rate_date->format('Y-m-d') => [(float) $r->rate, $r->source]])
        ->all();
}

it('el programador lo corre dos veces al día, en hora de Costa Rica', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pivox:sync-bccr-rates'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6,13 * * *')
        ->and($event->timezone)->toBe('America/Costa_Rica')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('trae el de hoy y completa los días anteriores que faltan, sin tocar los que ya están', function () {
    bccrFakeByDay();
    ['company' => $company] = logInAsCompanyUser();
    ExchangeRate::withoutGlobalScope(CompanyScope::class)->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id, 'rate_date' => '2026-10-12',
        'rate_type' => 'reference', 'rate' => '999', 'source' => 'manual', 'is_locked' => false,
    ]);

    $this->artisan('pivox:sync-bccr-rates', ['--date' => '2026-10-14', '--catch-up' => 3])
        ->expectsOutputToContain('1 compañía(s) al día. Se completaron 2 tasa(s) de días anteriores que faltaban.')
        ->assertSuccessful();

    expect(bccrRatesOf($company))->toBe([
        '2026-10-11' => [511.0, 'bccr_api'],
        '2026-10-12' => [999.0, 'manual'],
        '2026-10-13' => [513.0, 'bccr_api'],
        '2026-10-14' => [514.0, 'bccr_api'],
    ]);

    // El 12 ya estaba: ni se le pregunta al BCCR.
    Http::assertSentCount(3);

    expect(BccrSyncStatus::last())->toMatchArray(['ok' => true, 'date' => '2026-10-14'])
        // Lo que ve cada compañía: el tipo de cambio, no cuántas compañías hay.
        ->and(BccrSyncStatus::last()['message'])->toBe('Tipo de cambio del 14/10/2026: 514.000000.');

    $this->get(route('exchange-rates.index'))->assertInertia(fn ($page) => $page
        ->where('bccrLastSync.ok', true)
        ->where('bccrLastSync.date', '2026-10-14'));
});

it('sin fecha toma el día de Costa Rica: de noche, en UTC ya es mañana', function () {
    bccrFakeByDay();
    ['company' => $company] = logInAsCompanyUser();
    $this->travelTo(Carbon::parse('2026-10-15 01:30:00', 'UTC'));

    $this->artisan('pivox:sync-bccr-rates', ['--catch-up' => 0])->assertSuccessful();

    expect(array_keys(bccrRatesOf($company)))->toBe(['2026-10-14']);
});

it('si el BCCR falla, queda constancia y Tipos de cambio lo muestra', function () {
    Http::fake([BCCR_AUTO_URL => Http::response(['CodigoError' => '401'], 401)]);
    logInAsCompanyUser();

    $this->artisan('pivox:sync-bccr-rates', ['--date' => '2026-10-14'])->assertFailed();

    expect(BccrSyncStatus::last())->toMatchArray(['ok' => false, 'date' => '2026-10-14']);

    $this->get(route('exchange-rates.index'))->assertInertia(fn ($page) => $page
        ->where('bccrLastSync.ok', false)
        // Sin hablar del token: las compañías no lo pueden arreglar.
        ->where('bccrLastSync.message', 'La conexión de Pivox con el BCCR no está funcionando. Avisale al equipo de Pivox.'));
});
