<?php

use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| «Modelo y consumo»: cada persona elige su modelo y ve lo que gastó
|--------------------------------------------------------------------------
|
| El modelo es de la persona, entre los de conti.models que la key puede
| usar. El consumo es el suyo (hoy, semana, mes), con los límites que le
| aplican: el suyo y los de la licencia.
|
*/

const OPENAI_MODELS_URL = 'https://api.openai.com/v1/models';

/** La key puede usar estos modelos. */
function fakeOpenAiModels(array $ids, array $more = []): void
{
    Http::fake([OPENAI_MODELS_URL => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $ids)]), ...$more]);
}

/** Un mensaje que ya gastó, a esa hora de Costa Rica. */
function contiSpent(array $f, string $at, float $credits, int $prompt, int $completion, $user = null): void
{
    $row = new ContiUsage([
        'license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => ($user ?? $f['user'])->id,
        'model' => 'gpt-4.1-mini', 'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'credits' => $credits, 'cost_usd' => $credits / 100,
    ]);
    $row->created_at = Carbon::parse($at, 'America/Costa_Rica')->utc();
    $row->save();
}

it('muestra los modelos que la key puede usar, el suyo, y su consumo de hoy, la semana y el mes con los límites', function () {
    configureOpenAi();
    // Martes 6 de octubre: la semana empezó el lunes 5 y el mes el jueves 1.
    $this->travelTo(Carbon::parse('2026-10-06 15:00', 'America/Costa_Rica'));
    fakeOpenAiModels(['gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini', 'o1-pro']);
    $f = contiLicensed(['ai_daily_credits' => 100, 'ai_weekly_credits' => 500, 'ai_user_daily_credits' => 20]);
    ['user' => $other] = contiUser([], $f['company']);

    contiSpent($f, '2026-10-06 09:00', 3, 1000, 200);
    contiSpent($f, '2026-10-05 10:00', 5, 2000, 300);
    contiSpent($f, '2026-10-02 10:00', 7, 3000, 500);
    contiSpent($f, '2026-09-30 10:00', 9, 9000, 900);   // el mes pasado
    contiSpent($f, '2026-10-06 08:00', 50, 40000, 4000, $other);

    $response = $this->actingAs($f['user'])->getJson(route('conti.settings.show'))->assertOk();

    expect($response->json('modelo'))->toBe('gpt-4.1-mini')
        // Los de conti.models que la key puede usar, en su orden: ni nano ni GPT-5 (no los tiene), ni o1-pro (no tiene precio).
        ->and(array_column($response->json('modelos'), 'id'))->toBe(['gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini'])
        ->and($response->json('modelos.0.predeterminado'))->toBeTrue()
        ->and($response->json('modelos.2.razona'))->toBeTrue()
        ->and($response->json('hoy'))->toBe(['mensajes' => 1, 'tokens' => 1200, 'tokens_entrada' => 1000, 'tokens_salida' => 200, 'creditos' => '3.00'])
        ->and($response->json('semana.tokens'))->toBe(3500)
        ->and($response->json('semana.creditos'))->toBe('8.00')
        ->and($response->json('mes.tokens'))->toBe(7000)
        ->and($response->json('mes.mensajes'))->toBe(3)
        // Primero el suyo; los de la licencia cuentan también a la otra persona.
        ->and($response->json('limites'))->toBe([
            ['clave' => 'user_daily', 'nombre' => 'Tu límite de hoy', 'limite' => '20.00', 'usado' => '3.00', 'renueva' => 'mañana a las 00:00'],
            ['clave' => 'daily', 'nombre' => 'De la licencia, hoy', 'limite' => '100.00', 'usado' => '53.00', 'renueva' => 'mañana a las 00:00'],
            ['clave' => 'weekly', 'nombre' => 'De la licencia, esta semana', 'limite' => '500.00', 'usado' => '58.00', 'renueva' => 'el lunes a las 00:00'],
        ]);
});

it('la persona elige su modelo y Conti le responde con ese', function () {
    configureOpenAi();
    fakeOpenAiModels(['gpt-4.1-mini', 'gpt-4.1'], [OPENAI_URL => Http::response(openAiReply('Hola.'))]);
    $f = contiLicensed();

    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-4.1'])
        ->assertOk()
        ->assertJson(['modelo' => 'gpt-4.1', 'nombre' => 'GPT-4.1']);
    expect($f['user']->fresh()->conti_model)->toBe('gpt-4.1');

    sendToConti($this, $f)->assertOk();
    Http::assertSent(fn (Request $request) => $request->url() === OPENAI_URL
        && $request['model'] === 'gpt-4.1'
        && $request['max_completion_tokens'] === 1500
        && ! isset($request['reasoning_effort']));

    $this->actingAs($f['user'])->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.model', 'GPT-4.1'));

    // Volver al predeterminado no lo guarda: sigue al de la instalación.
    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-4.1-mini'])->assertOk();
    expect($f['user']->fresh()->conti_model)->toBeNull();
});

it('a un modelo que razona se le pide un razonamiento corto y se le deja escribir más', function () {
    configureOpenAi();
    fakeOpenAiModels(['gpt-5-mini'], [OPENAI_URL => Http::response(openAiReply('Hola.'))]);
    $f = contiLicensed();

    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-5-mini'])->assertOk();
    sendToConti($this, $f)->assertOk();

    Http::assertSent(fn (Request $request) => $request->url() === OPENAI_URL
        && $request['model'] === 'gpt-5-mini'
        && $request['reasoning_effort'] === 'low'
        && $request['max_completion_tokens'] === 4000);
});

it('no se puede elegir un modelo fuera de la lista ni uno que la key no puede usar', function () {
    configureOpenAi();
    fakeOpenAiModels(['gpt-4.1-mini', 'gpt-4.1', 'o1-pro']);
    $f = contiLicensed();

    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-5'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['modelo' => 'Ese modelo no está disponible. Elegí uno de la lista.']);
    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'o1-pro'])->assertStatus(422);

    expect($f['user']->fresh()->conti_model)->toBeNull();
});

it('si el modelo elegido ya no se ofrece, Conti responde con el predeterminado', function () {
    configureOpenAi();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Hola.'))]);
    $f = contiLicensed();
    $f['user']->forceFill(['conti_model' => 'gpt-3.5-turbo'])->save();

    sendToConti($this, $f)->assertOk();

    Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-4.1-mini');
});

it('la lista de modelos de la key se recuerda; si OpenAI no contesta, se ofrecen todos y se vuelve a preguntar', function () {
    configureOpenAi();
    $f = contiLicensed();

    Http::fake([OPENAI_MODELS_URL => Http::sequence()
        ->push(['error' => ['message' => 'Server error']], 500)
        ->push(['data' => [['id' => 'gpt-4.1-mini'], ['id' => 'gpt-4.1-nano']]])]);

    $first = $this->actingAs($f['user'])->getJson(route('conti.settings.show'))->assertOk();
    expect($first->json('modelos'))->toHaveCount(count(config('conti.models')));

    $this->actingAs($f['user'])->getJson(route('conti.settings.show'));
    $third = $this->actingAs($f['user'])->getJson(route('conti.settings.show'));

    expect(array_column($third->json('modelos'), 'id'))->toBe(['gpt-4.1-nano', 'gpt-4.1-mini']);
    Http::assertSentCount(2);
});

it('con la licencia vencida se ve el consumo y se cambia el modelo; sin Conti en la licencia, no', function () {
    configureOpenAi();
    fakeOpenAiModels(['gpt-4.1-mini', 'gpt-4.1']);

    $f = contiUser([], null, true);
    $expired = License::factory()->expired()->create(['superuser_id' => $f['user']->id, 'ai_enabled' => true]);
    $f['company']->update(['license_id' => $expired->id]);

    $this->actingAs($f['user'])->getJson(route('conti.settings.show'))->assertOk();
    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-4.1'])->assertOk();

    $expired->update(['ai_enabled' => false]);
    $this->actingAs($f['user'])->getJson(route('conti.settings.show'))->assertForbidden();
    $this->actingAs($f['user'])->putJson(route('conti.settings.model'), ['modelo' => 'gpt-4.1-mini'])->assertForbidden();
});

it('el precio de un modelo es el de su nombre exacto o su versión con fecha, no el de otro que empiece igual', function () {
    $usage = app(ContiUsageService::class);
    $pricing = config('conti.pricing');

    expect($usage->pricingFor('gpt-5-mini-2025-08-07'))->toBe($pricing['gpt-5-mini'])
        ->and($usage->pricingFor('gpt-4o-mini-2024-07-18'))->toBe($pricing['gpt-4o-mini'])
        ->and($usage->pricingFor('gpt-5'))->toBe($pricing['gpt-5'])
        ->and($usage->pricingFor('gpt-5.4'))->toBe(config('conti.fallback_pricing'))
        ->and($usage->pricingFor('gpt-5-chat-latest'))->toBe(config('conti.fallback_pricing'));

    // Todo modelo que se ofrece tiene su precio: si no, el consumo saldría mal.
    foreach (array_keys(config('conti.models')) as $model) {
        expect($pricing)->toHaveKey($model);
    }
});
