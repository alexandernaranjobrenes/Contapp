<?php

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Models\ContiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El chat: CONTAPP le habla a OpenAI y le da las herramientas
|--------------------------------------------------------------------------
|
| El ciclo corre en el servidor: el modelo pide herramientas, se ejecutan a
| nombre de la persona y se le devuelve el resultado. Cada mensaje queda
| registrado con lo que costó, y la licencia decide si hay Conti y cuánto.
|
*/

it('sin la key de OpenAI, Conti no aparece y no responde', function () {
    config(['services.openai.key' => null]);
    $f = contiUser([], null, true);

    $this->actingAs($f['user'])->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', false));
    sendToConti($this, $f)->assertStatus(503);
});

it('solo aparece si la licencia lo tiene activado', function () {
    configureOpenAi();
    Http::fake();
    $f = contiLicensed(['ai_enabled' => false]);

    $this->actingAs($f['user'])->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', false));
    sendToConti($this, $f)->assertForbidden()->assertJson(['message' => 'Conti no está activado en la licencia de esta compañía. Si querés usarlo, contactá al equipo de CONTAPP.']);

    $f['license']->update(['ai_enabled' => true]);
    $this->actingAs($f['user'])->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', true));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'openai.com'));
});

it('el modelo usa una herramienta, recibe el resultado y responde; el consumo queda registrado', function () {
    configureOpenAi();
    $f = contiLicensed();
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'C-777', 'name' => 'Ferretería Central']);

    Http::fake([OPENAI_URL => Http::sequence()
        ->push(openAiReply(null, [['consultar', ['consulta' => 'socios']]], prompt: 2000, completion: 50))
        ->push(openAiReply('Tenés **1** socio: Ferretería Central (C-777).', prompt: 3000, completion: 100, cached: 2000))]);

    sendToConti($this, $f, '¿Cuántos socios tengo?')
        ->assertOk()
        ->assertJson(['respuesta' => 'Tenés **1** socio: Ferretería Central (C-777).', 'aviso' => null]);

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($requests)->toHaveCount(2);

    $first = $requests[0];
    expect($first->hasHeader('Authorization', 'Bearer sk-prueba'))->toBeTrue()
        ->and($first['model'])->toBe('gpt-4.1-mini')
        ->and($first['messages'][0]['content'])->toContain('Te llamás **Conti**')
        ->and($first['messages'][0]['content'])->toContain("Persona: {$f['user']->name} (Superusuario)")
        ->and($first['messages'][0]['content'])->toContain('socios (Socios de negocio)')
        ->and(collect($first['tools'])->pluck('function.name')->all())->toBe(['manual', 'contexto', 'consultar', 'reporte', 'preparar_accion', 'estado_accion']);

    // El resultado de la herramienta le llegó al modelo en la segunda vuelta.
    $toolMessage = collect($requests[1]['messages'])->firstWhere('role', 'tool');
    expect($toolMessage['tool_call_id'])->toBe('call_0')
        ->and($toolMessage['content'])->toContain('C-777');

    // 5000 tokens de entrada (2000 en caché), 150 de salida, a precio de gpt-4.1-mini:
    // 3000 × 0,40 + 2000 × 0,10 + 150 × 1,60 = 1640 por millón = US$0,00164 = 0,164 créditos.
    $usage = ContiUsage::sole();
    expect($usage->license_id)->toBe($f['license']->id)
        ->and($usage->user_id)->toBe($f['user']->id)
        ->and($usage->prompt_tokens)->toBe(5000)
        ->and($usage->cached_tokens)->toBe(2000)
        ->and($usage->completion_tokens)->toBe(150)
        ->and($usage->tool_calls)->toBe(1)
        ->and((string) $usage->cost_usd)->toBe('0.001640')
        ->and((string) $usage->credits)->toBe('0.1640')
        ->and($usage->status)->toBe('ok');
});

it('recuerda la conversación: el mensaje siguiente lleva lo hablado', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()->push(openAiReply('Hola, soy Conti.'))->push(openAiReply('Sí, te dije hola.'))]);

    sendToConti($this, $f, 'Hola', 'conv-1')->assertOk();
    sendToConti($this, $f, '¿Qué me dijiste?', 'conv-1')->assertOk();

    $messages = collect(Http::recorded()[1][0]['messages']);
    expect($messages->pluck('content')->slice(1)->values()->all())->toBe(['Hola', 'Hola, soy Conti.', '¿Qué me dijiste?']);
});

it('al llegar al límite diario de la licencia no llama al modelo y dice cuándo se renueva', function () {
    configureOpenAi();
    Http::fake();
    $f = contiLicensed(['ai_daily_credits' => 10]);
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $f['user']->id, 'model' => 'gpt-4.1-mini', 'credits' => 10]);

    sendToConti($this, $f)
        ->assertStatus(429)
        ->assertJson(['message' => 'Se alcanzó el límite diario de Conti de esta licencia (10 créditos), que comparten todas sus personas. Se renueva mañana a las 00:00.']);

    Http::assertNothingSent();
});

it('el límite semanal cuenta desde el lunes, y el de persona solo lo de esa persona', function () {
    configureOpenAi();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Listo.'))]);
    $f = contiLicensed(['ai_weekly_credits' => 50, 'ai_user_daily_credits' => 5]);
    ['user' => $colleague] = contiUser([], $f['company']);

    // Lo de la semana pasada no cuenta; lo de un colega no cuenta para el límite por persona.
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $f['user']->id, 'model' => 'x', 'credits' => 100])
        ->forceFill(['created_at' => now('America/Costa_Rica')->startOfWeek()->subDay()->setTimezone('UTC')])->save();
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $colleague->id, 'model' => 'x', 'credits' => 20]);

    sendToConti($this, $f)->assertOk();

    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $f['user']->id, 'model' => 'x', 'credits' => 5]);
    sendToConti($this, $f)->assertStatus(429)->assertJson(['message' => 'Llegaste a tu límite diario de Conti (5 créditos). Se renueva mañana a las 00:00.']);

    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $colleague->id, 'model' => 'x', 'credits' => 30]);
    $this->actingAs($colleague)->postJson(route('conti.messages.store'), ['mensaje' => 'Hola', 'sesion' => 'x'])
        ->assertStatus(429)
        ->assertJson(['message' => 'Se alcanzó el límite semanal de Conti de esta licencia (50 créditos), que comparten todas sus personas. Se renueva el lunes a las 00:00.']);
});

it('avisa cuando un límite va por encima del 80 %', function () {
    configureOpenAi();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Listo.'))]);
    $f = contiLicensed(['ai_daily_credits' => 10]);
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $f['user']->id, 'model' => 'x', 'credits' => 8.5]);

    sendToConti($this, $f)->assertOk()->assertJsonPath('aviso', 'Ya se usó el 85 % del límite diario de la licencia de Conti.');
});

it('una compañía sin licencia (de desarrollo) tiene Conti sin límites', function () {
    configureOpenAi();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Hola.'))]);
    $f = contiUser([], null, true);

    sendToConti($this, $f)->assertOk();
    expect(ContiUsage::sole()->license_id)->toBeNull();
});

it('si OpenAI falla a la mitad, lo dice sin detalles técnicos y cobra lo que ya se consumió', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()
        ->push(openAiReply(null, [['manual', ['tema' => 'asientos']]], prompt: 1000, completion: 20))
        ->push(['error' => ['message' => 'Internal error']], 500)]);

    sendToConti($this, $f)
        ->assertStatus(502)
        ->assertJson(['message' => 'Conti no pudo responder esta vez. Probá de nuevo; si se repite, avisale al equipo de CONTAPP.']);

    expect(ContiUsage::sole()->status)->toBe('error')
        ->and(ContiUsage::sole()->prompt_tokens)->toBe(1000);
});

it('si la key no sirve o no hay conexión, lo dice', function () {
    configureOpenAi();
    $f = contiLicensed();

    Http::fake([OPENAI_URL => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);
    sendToConti($this, $f)->assertStatus(503)->assertJson(['message' => 'Conti no está bien configurado (la key de OpenAI no es válida). Avisale al equipo de CONTAPP.']);

    Http::fake(fn () => throw new ConnectionException('timeout'));
    sendToConti($this, $f)->assertStatus(503);

    expect(ContiUsage::count())->toBe(0);
});

it('la sesión del chat solo acepta letras, números y guiones', function () {
    configureOpenAi();
    Http::fake();
    $f = contiLicensed();

    sendToConti($this, $f, 'Hola', 'u1-c2/../otra')->assertStatus(422);
    Http::assertNothingSent();
});

it('con la licencia vencida se puede seguir hablando con Conti', function () {
    configureOpenAi();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Sí.'))]);
    $f = contiLicensed();
    $f['license']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    sendToConti($this, $f)->assertOk();

    expect(Http::recorded()[0][0]['messages'][0]['content'])->toContain('La licencia está vencida (modo de gracia)');
});

it('la última vuelta ya no ofrece herramientas: el modelo tiene que responder', function () {
    configureOpenAi();
    config(['conti.max_iterations' => 2]);
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()
        ->push(openAiReply(null, [['manual', ['tema' => 'socios']]]))
        ->push(openAiReply('Respuesta final.'))]);

    sendToConti($this, $f)->assertOk()->assertJson(['respuesta' => 'Respuesta final.']);

    Http::assertSent(fn (Request $request) => $request['tool_choice'] === 'none');
});

it('al enlace para confirmar se le quita el dominio que invente el modelo', function () {
    configureOpenAi();
    $f = contiLicensed();
    $uuid = '9448b898-6513-4866-8e8f-bd0346dfaf52';
    Http::fake([OPENAI_URL => Http::sequence()->push(openAiReply(
        "Listo. [Revisalo y confirmalo acá](https://app.contapp.run/conti/acciones/{$uuid}). También: app.contapp.co/conti/acciones/{$uuid} y [la ayuda](https://contapp.com/ayuda).",
    ))]);

    sendToConti($this, $f)->assertOk()->assertJson([
        'respuesta' => "Listo. [Revisalo y confirmalo acá](/conti/acciones/{$uuid}). También: /conti/acciones/{$uuid} y [la ayuda](https://contapp.com/ayuda).",
    ]);
});
