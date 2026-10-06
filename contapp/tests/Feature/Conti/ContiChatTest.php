<?php

use App\Domains\Conti\Services\ContiTokenService;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El chat: CONTAPP le pasa cada mensaje a n8n desde el servidor
|--------------------------------------------------------------------------
|
| El pase de la API viaja en la metadata del mensaje y nunca llega al
| navegador. El Chat Trigger de n8n se protege con autenticación básica.
|
*/

const CONTI_WEBHOOK = 'https://n8n.ejemplo.com/webhook/conti/chat';

function configureConti(): void
{
    config([
        'services.conti.webhook_url' => CONTI_WEBHOOK,
        'services.conti.webhook_user' => 'contapp',
        'services.conti.webhook_password' => 'secreto',
    ]);
}

it('sin el flujo de n8n configurado, Conti no aparece y no responde', function () {
    config(['services.conti.webhook_url' => null]);
    ['user' => $user] = contiUser([], null, true);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', false));
    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'Hola', 'sesion' => 'abc'])->assertStatus(503);
});

it('configurado, aparece y le pasa el mensaje a n8n con el pase y quién pregunta', function () {
    configureConti();
    Http::fake([CONTI_WEBHOOK => Http::response(['output' => '¡Hola! Soy Conti.'])]);
    ['user' => $user, 'company' => $company] = contiUser([], null, true);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', true));

    $this->actingAs($user)
        ->postJson(route('conti.messages.store'), ['mensaje' => '¿Quién sos?', 'sesion' => 'abc-123', 'pantalla' => 'Panel [dashboard]'])
        ->assertOk()
        ->assertJson(['respuesta' => '¡Hola! Soy Conti.']);

    Http::assertSent(function (Request $request) use ($user, $company) {
        $token = app(ContiTokenService::class)->resolve($request['metadata']['contiToken'] ?? null);

        return $request->url() === CONTI_WEBHOOK
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('contapp:secreto'))
            && $request['action'] === 'sendMessage'
            && $request['chatInput'] === '¿Quién sos?'
            && $request['sessionId'] === "u{$user->id}-c{$company->id}-abc-123"
            && $request['metadata']['usuario'] === $user->name
            && $request['metadata']['rol'] === 'Superusuario'
            && $request['metadata']['pantalla'] === 'Panel [dashboard]'
            && $token?->user_id === $user->id
            && $token?->company_id === $company->id;
    });
});

it('entiende la respuesta en lista y la que llega por partes', function () {
    configureConti();
    ['user' => $user] = contiUser([], null, true);

    Http::fake([CONTI_WEBHOOK => Http::sequence()
        ->push([['output' => 'En lista']])
        ->push("{\"type\":\"begin\"}\n{\"type\":\"item\",\"content\":\"Por \"}\n{\"type\":\"item\",\"content\":\"partes\"}\n{\"type\":\"end\"}")]);

    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'a', 'sesion' => 'x'])->assertJson(['respuesta' => 'En lista']);
    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'b', 'sesion' => 'x'])->assertJson(['respuesta' => 'Por partes']);
});

it('si n8n falla o no responde, lo dice sin mostrar detalles', function () {
    configureConti();
    ['user' => $user] = contiUser([], null, true);

    Http::fake([CONTI_WEBHOOK => Http::response('Internal error', 500)]);
    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'a', 'sesion' => 'x'])
        ->assertStatus(502)
        ->assertJson(['message' => 'Conti no pudo responder esta vez. Probá de nuevo; si se repite, avisale al equipo de CONTAPP.']);

    Http::fake(fn () => throw new ConnectionException('timeout'));
    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'a', 'sesion' => 'x'])->assertStatus(502);
});

it('la sesión del chat solo acepta letras, números y guiones', function () {
    configureConti();
    Http::fake();
    ['user' => $user] = contiUser([], null, true);

    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'a', 'sesion' => 'u1-c2/../otra'])->assertStatus(422);
    Http::assertNothingSent();
});

it('con la licencia vencida se puede seguir hablando con Conti', function () {
    configureConti();
    Http::fake([CONTI_WEBHOOK => Http::response(['output' => 'Sí'])]);
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    $license = License::factory()->expired()->create(['superuser_id' => $user->id]);
    $company->update(['license_id' => $license->id]);

    $this->actingAs($user)->postJson(route('conti.messages.store'), ['mensaje' => 'a', 'sesion' => 'x'])->assertOk();
});
