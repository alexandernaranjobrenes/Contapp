<?php

use App\Domains\Pricing\Models\LicenseRequest;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Pricing/helpers.php';

/*
|--------------------------------------------------------------------------
| Detrás de Cloudflare (config/trustedproxy.php)
|--------------------------------------------------------------------------
|
| En producción cada request llega por Cloudflare, o por un proxy de la red
| interna del hosting, con la IP del visitante en X-Forwarded-For y el https
| en X-Forwarded-Proto. Se les cree solo si vienen de un proxy de la lista.
|
*/

const PROXY_IP = '172.18.0.9';

beforeEach(function () {
    Notification::fake();
    pricingCatalogFixture();
});

/** Una solicitud de licencia que llega por un proxy de confianza, con este X-Forwarded-For. */
function postThroughProxy(string $forwardedFor, array $payload = [], string $remote = PROXY_IP)
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remote])
        ->withHeaders(['X-Forwarded-For' => $forwardedFor, 'X-Forwarded-Proto' => 'https'])
        ->postJson('/precios/solicitud', requestPayload($payload));
}

it('la IP es la del visitante, no la del proxy', function () {
    postThroughProxy('203.0.113.50')->assertCreated();

    expect(LicenseRequest::sole()->ip_address)->toBe('203.0.113.50');
});

it('un X-Forwarded-For inventado por el navegador no cambia la IP', function (string $chain) {
    postThroughProxy($chain)->assertCreated();

    expect(LicenseRequest::sole()->ip_address)->toBe('203.0.113.50');
})->with([
    // Cloudflare agrega la IP real al final de lo que mandó el navegador.
    'inventada antes de la real' => '198.51.100.7, 203.0.113.50',
    // Si en la cadena quedara una IP de Cloudflare, tampoco cuenta.
    'con una IP de Cloudflare al final' => '203.0.113.50, 162.158.10.20',
]);

it('sin un proxy de confianza adelante, la cabecera no cuenta', function () {
    postThroughProxy('198.51.100.7', remote: '203.0.113.99')->assertCreated();

    expect(LicenseRequest::sole()->ip_address)->toBe('203.0.113.99');
});

it('el límite de solicitudes es por visitante, no uno para todos', function () {
    foreach (range(1, 5) as $n) {
        postThroughProxy('203.0.113.50', ['email' => "ana{$n}@lasabana.test"])->assertCreated();
    }

    postThroughProxy('203.0.113.50')->assertStatus(429);
    postThroughProxy('203.0.113.51')->assertCreated();
});

it('con X-Forwarded-Proto https, los enlaces salen con https', function () {
    $this->withServerVariables(['REMOTE_ADDR' => PROXY_IP])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.50', 'X-Forwarded-Proto' => 'https'])
        ->get('/precios')
        ->assertOk()
        ->assertSee('data-request-url="https://', false);
});

it('sin un proxy de confianza adelante, X-Forwarded-Proto no cuenta', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('/precios')
        ->assertSee('data-request-url="http://', false);
});
