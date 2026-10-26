<?php

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Pricing\Models\LicenseRequest;
use App\Domains\Pricing\Models\PricingSetting;
use App\Notifications\LicenseRequestReceivedNotification;
use App\Notifications\LicenseRequestReplyNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Pedir una licencia desde /precios
|--------------------------------------------------------------------------
|
| La solicitud se guarda con la cotización que calcula el servidor y se le
| avisa al backoffice. El backoffice decide si la responde por correo o la
| descarta (CLAUDE.md secc. 42).
|
*/

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('hour:127.0.0.1');
    RateLimiter::clear('day:127.0.0.1');
});

it('muestra la página de precios con los paquetes y el catálogo para el configurador', function () {
    pricingCatalogFixture();

    $this->get('/precios')
        ->assertOk()
        ->assertSee('Pyme')
        ->assertSee('data-island="pricing"', false)
        ->assertSee('id="pricing-catalog"', false)
        ->assertSee('"@type":"FAQPage"', false)
        // 55.000 menos el 10 %, sin IVA.
        ->assertSee('₡49.500');
});

it('sin funciones activas, la página invita a escribir', function () {
    $this->get('/precios')->assertOk()->assertDontSee('data-island="pricing"', false)->assertSee('servicioalcliente@pivoxcr.com');
});

it('guarda la solicitud con la cotización del servidor y avisa al backoffice', function () {
    pricingCatalogFixture();
    PricingSetting::current()->update(['notification_email' => 'servicios@ncodedigital.com']);

    $this->postJson('/precios/solicitud', requestPayload([
        // Lo que diga el navegador del precio no cuenta.
        'total' => 1,
    ]))->assertCreated()->assertJson(['reference' => 'SOL-000001', 'email' => 'ana@lasabana.test']);

    $request = LicenseRequest::sole();

    expect($request->status)->toBe(LicenseRequest::STATUS_NEW)
        ->and($request->package_name)->toBe('Pyme')
        ->and((float) $request->total)->toBe(55935.0)
        ->and($request->quote['discount'])->toBe(550_000)
        ->and($request->consented_at)->not->toBeNull();

    Notification::assertSentTo(
        new AnonymousNotifiable,
        LicenseRequestReceivedNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'servicios@ncodedigital.com',
    );
});

it('un paquete con una función quitada se guarda como personalizado', function () {
    pricingCatalogFixture();

    $this->postJson('/precios/solicitud', requestPayload(['selection' => ['contabilidad' => 1, 'inventario' => 1, 'companias' => 3]]))->assertCreated();

    expect(LicenseRequest::sole()->package_name)->toBe('Pyme (personalizado)');
});

it('pide los datos y el consentimiento', function () {
    pricingCatalogFixture();

    $this->postJson('/precios/solicitud', requestPayload(['contact_name' => '', 'email' => 'no-es-correo', 'consent' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_name', 'email', 'consent']);

    expect(LicenseRequest::count())->toBe(0);
});

it('al robot que llena el campo trampa le contesta como si nada, sin guardar', function () {
    pricingCatalogFixture();

    $this->postJson('/precios/solicitud', requestPayload(['website' => 'https://spam.test']))->assertCreated();

    expect(LicenseRequest::count())->toBe(0);
    Notification::assertNothingSent();
});

it('rechaza un envío de una página recién abierta o con el tiempo adulterado', function (string $started) {
    pricingCatalogFixture();

    $this->postJson('/precios/solicitud', requestPayload(['started' => $started]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('form');

    expect(LicenseRequest::count())->toBe(0);
})->with([
    'recién abierta' => fn () => Crypt::encryptString((string) now()->timestamp),
    'adulterado' => fn () => 'no-cifrado',
    'abierta hace dos días' => fn () => Crypt::encryptString((string) now()->subDays(2)->timestamp),
]);

it('limita los envíos por IP', function () {
    pricingCatalogFixture();

    foreach (range(1, 5) as $n) {
        $this->postJson('/precios/solicitud', requestPayload(['email' => "ana{$n}@lasabana.test"]))->assertCreated();
    }

    $this->postJson('/precios/solicitud', requestPayload())->assertStatus(429);
});

it('alguien con la licencia vencida igual puede pedir una', function () {
    pricingCatalogFixture();
    app(CurrentCompany::class)->setGraceMode(true);

    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
});

it('el backoffice ve las solicitudes y las responde por correo, con la cotización', function () {
    pricingCatalogFixture();
    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
    $request = LicenseRequest::sole();
    $propietario = loginAsPropietario();

    $this->get(route('backoffice.license-requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Backoffice/LicenseRequests/Index')->where('requests.0.reference', 'SOL-000001'));

    $this->post(route('backoffice.license-requests.reply', $request), [
        'subject' => 'Tu licencia de Pivox',
        'body' => "Hola, Ana:\n\nTe escribimos para coordinar el pago.",
        'include_quote' => 1,
    ])->assertSessionHas('success');

    expect($request->fresh()->status)->toBe(LicenseRequest::STATUS_REPLIED)
        ->and($request->fresh()->handled_by)->toBe($propietario->id)
        ->and($request->replies()->sole()->status)->toBe('sent');

    Notification::assertSentTo(
        new AnonymousNotifiable,
        LicenseRequestReplyNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ana@lasabana.test',
    );
});

it('si el correo no sale, queda anotado y la solicitud sigue nueva', function () {
    pricingCatalogFixture();
    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
    $request = LicenseRequest::sole();
    loginAsPropietario();

    // Notification::route() arma un AnonymousNotifiable, que despacha con send().
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP caído'));

    $this->post(route('backoffice.license-requests.reply', $request), ['subject' => 'Hola', 'body' => 'Texto', 'include_quote' => 0])
        ->assertSessionHas('error');

    expect($request->fresh()->status)->toBe(LicenseRequest::STATUS_NEW)
        ->and($request->replies()->sole())->status->toBe('failed')->error->toContain('SMTP caído');
});

it('los dos correos se arman con la cotización', function () {
    pricingCatalogFixture();
    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
    $request = LicenseRequest::sole();

    $received = (string) (new LicenseRequestReceivedNotification($request))->toMail(new AnonymousNotifiable)->render();
    $reply = (string) (new LicenseRequestReplyNotification($request, 'Tu licencia', "Hola, Ana:\n\nTe escribimos <b>pronto</b>.", true))
        ->toMail(new AnonymousNotifiable)->render();

    expect($received)
        ->toContain('SOL-000001')
        ->toContain('Distribuidora La Sabana S.A.')
        ->toContain('₡55.935')
        ->toContain('Descuento del paquete')
        ->and($reply)
        ->toContain('Te escribimos &lt;b&gt;pronto&lt;/b&gt;.')
        ->toContain('Total por mes');
});

it('descartar y reabrir', function () {
    pricingCatalogFixture();
    $this->postJson('/precios/solicitud', requestPayload())->assertCreated();
    $request = LicenseRequest::sole();
    loginAsPropietario();

    $this->post(route('backoffice.license-requests.discard', $request), ['note' => 'Duplicada'])->assertSessionHas('success');
    expect($request->fresh())->status->toBe(LicenseRequest::STATUS_DISCARDED)->internal_note->toBe('Duplicada');

    $this->post(route('backoffice.license-requests.reopen', $request))->assertSessionHas('success');
    expect($request->fresh()->status)->toBe(LicenseRequest::STATUS_NEW);

    Notification::assertNotSentTo(new AnonymousNotifiable, LicenseRequestReplyNotification::class);
});
