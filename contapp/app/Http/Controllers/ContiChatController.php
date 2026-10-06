<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Agent\ContiAgent;
use App\Domains\Conti\Agent\ContiAgentException;
use App\Domains\Conti\Services\ContiAccessService;
use App\Domains\Conti\Services\ContiModelService;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Support\Facades\Cache;

/**
 * POST /conti/mensajes: lo que la persona le escribe a Conti desde el chat de
 * la aplicación (ContiPanel.vue), y su respuesta (CLAUDE.md secc. 32).
 *
 * 1. Revisa que la licencia tenga Conti y le quede cupo (ContiUsageService):
 *    si no, responde el motivo sin llamar al modelo.
 * 2. Corre el agente (ContiAgent) a nombre de la persona, en su compañía, con
 *    el modelo que ella eligió (ContiModelService).
 * 3. Registra lo consumido —también si el modelo falló a la mitad: OpenAI
 *    lo cobra igual— y avisa si algún límite va por encima del 80 %.
 *
 * La conversación se recuerda en caché unas horas, por persona, compañía y
 * conversación del chat. Nunca en la base: solo se guarda el consumo.
 */
class ContiChatController extends Controller
{
    public function store(
        Request $request,
        CurrentCompany $currentCompany,
        ContiUsageService $usage,
        ContiContext $context,
        ContiAgent $agent,
        ContiModelService $models,
        ContiAccessService $access,
    ): JsonResponse {
        // Esta petición puede tardar lo que tarde el modelo. Si al terminar
        // guardara la sesión, pisaría lo que otra pestaña haya cambiado
        // mientras tanto (un cambio de compañía, un aviso). No tiene nada que
        // guardar en ella: se la deja de solo lectura.
        $request->session()->setHandler(new NullSessionHandler);

        $validated = $request->validate([
            'mensaje' => ['required', 'string', 'max:4000'],
            'sesion' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'pantalla' => ['nullable', 'string', 'max:150'],
        ]);

        $companyId = $currentCompany->id();

        if ($companyId === null) {
            return response()->json(['message' => 'Elegí una compañía para hablar con Conti.'], 409);
        }

        $user = $request->user();
        $company = Company::with('license')->findOrFail($companyId);

        if ($block = $usage->blockFor($user, $company)) {
            return response()->json(['message' => $block[1]], $block[0]);
        }

        $context->set($user, $company, $currentCompany->isInGracePeriod());
        @set_time_limit(180);

        $historyKey = "conti:history:{$user->id}:{$company->id}:{$validated['sesion']}";
        $history = Cache::get($historyKey, []);

        try {
            $reply = $agent->reply($history, $validated['mensaje'], $validated['pantalla'] ?? null, $models->forUser($user, $access->allowedModels($user, $company)));
        } catch (ContiAgentException $e) {
            if ($e->usage !== null) {
                $usage->record($user, $company, $e->usage, 'error');
            }

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $usage->record($user, $company, $reply->usage);

        $history = array_slice([
            ...$history,
            ['role' => 'user', 'content' => $validated['mensaje']],
            ['role' => 'assistant', 'content' => $reply->text],
        ], -max(2, (int) config('conti.history_messages', 16)));

        Cache::put($historyKey, $history, now()->addMinutes((int) config('conti.history_minutes', 360)));

        return response()->json([
            'respuesta' => $reply->text,
            'aviso' => $usage->warningFor($user, $company),
        ]);
    }
}
