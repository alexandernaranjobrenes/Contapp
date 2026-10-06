<?php

use App\Domains\Conti\Agent\ContiToolbox;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| Ayudas de las pruebas de Conti (CLAUDE.md secc. 32). Cada archivo de
| pruebas de esta carpeta las incluye.
*/

if (! function_exists('contiUser')) {
    /** Una persona de la compañía con estos niveles por pantalla (o Superusuario). */
    function contiUser(array $levels = [], ?Company $company = null, bool $superuser = false): array
    {
        $company ??= Company::factory()->create();
        $user = User::factory()->create([
            'default_company_id' => $company->id,
            'status' => 'active',
            'is_super_admin' => $superuser,
        ]);
        $company->users()->attach($user->id, ['is_default' => true]);

        foreach (['accounting', 'reports', 'billing', 'tax', 'inventory', 'banking', 'business_partners', 'payroll'] as $code) {
            Module::firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        }

        if ($levels !== []) {
            app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, $levels);
        }

        return compact('company', 'user');
    }

    /**
     * Las herramientas de Conti a nombre de esa persona, en esa compañía: lo
     * mismo que arma ContiChatController antes de llamar al modelo.
     */
    function contiTools(User $user, Company $company, ?bool $grace = null): ContiToolbox
    {
        $company = $company->fresh();
        // Como SetCurrentCompany: con la licencia vencida, modo de gracia.
        $grace ??= (bool) $company->license?->isExpiredButActive();

        app(CurrentCompany::class)->set($company->id);
        app(CurrentCompany::class)->setGraceMode($grace);
        app(ContiContext::class)->set($user, $company, $grace);

        return app(ContiToolbox::class);
    }

    /**
     * Conti prepara una acción (la herramienta «preparar_accion»). Devuelve
     * una respuesta de prueba: 201 con lo preparado, o el código del error
     * con sus errores de validación, para usar las aserciones de siempre.
     */
    function contiPrepare(array $f, string $action, array $data): TestResponse
    {
        $result = contiTools($f['user'], $f['company'])->run('preparar_accion', ['accion' => $action, 'datos' => $data]);

        if (isset($result['codigo'])) {
            return TestResponse::fromBaseResponse(response()->json(['message' => $result['error'], 'errors' => $result['detalle'] ?? []], $result['codigo']));
        }

        return TestResponse::fromBaseResponse(response()->json($result, 201));
    }

    /** En qué quedó una acción, como lo ve Conti (la herramienta «estado_accion»). */
    function contiActionStatus(array $f, string $uuid): array
    {
        return contiTools($f['user'], $f['company'])->run('estado_accion', ['id' => $uuid]);
    }

    /** Una respuesta de Chat Completions de OpenAI, para Http::fake. */
    function openAiReply(?string $content, array $toolCalls = [], int $prompt = 1000, int $completion = 100, int $cached = 0): array
    {
        $message = ['role' => 'assistant', 'content' => $content];

        if ($toolCalls !== []) {
            $message['tool_calls'] = array_map(fn (array $call, int $i) => [
                'id' => "call_{$i}",
                'type' => 'function',
                'function' => ['name' => $call[0], 'arguments' => json_encode($call[1])],
            ], $toolCalls, array_keys($toolCalls));
        }

        return [
            'id' => 'chatcmpl-prueba',
            'model' => 'gpt-4.1-mini-2025-04-14',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls ? 'tool_calls' : 'stop']],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'prompt_tokens_details' => ['cached_tokens' => $cached],
            ],
        ];
    }
}

if (! defined('OPENAI_URL')) {
    define('OPENAI_URL', 'https://api.openai.com/v1/chat/completions');
}

if (! function_exists('configureOpenAi')) {
    function configureOpenAi(): void
    {
        config(['services.openai.key' => 'sk-prueba', 'services.openai.model' => 'gpt-4.1-mini', 'services.openai.base_url' => 'https://api.openai.com/v1']);
    }

    /** Una licencia con Conti, y sus límites. */
    function contiLicensed(array $ai = []): array
    {
        $f = contiUser([], null, true);
        $license = License::factory()->create(['superuser_id' => $f['user']->id, 'ai_enabled' => true, ...$ai]);
        $f['company']->update(['license_id' => $license->id]);
        $f['license'] = $license;

        return $f;
    }

    function sendToConti($test, array $f, string $message = 'Hola', string $session = 'abc')
    {
        return $test->actingAs($f['user'])->postJson(route('conti.messages.store'), ['mensaje' => $message, 'sesion' => $session, 'pantalla' => 'Panel [dashboard]']);
    }
}
