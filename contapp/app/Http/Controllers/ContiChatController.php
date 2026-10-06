<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Services\ContiTokenService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * POST /conti/mensajes: lo que la persona le escribe a Conti, desde el chat
 * de la aplicación (ContiChat.vue).
 *
 * CONTAPP le pasa el mensaje al flujo de n8n desde el servidor, no el
 * navegador: así el pase de la API de Conti (ContiTokenService) nunca llega
 * al navegador, y el Chat Trigger de n8n puede exigir una contraseña que
 * solo CONTAPP conoce (config/services.php: conti).
 *
 * El pase va en la metadata del mensaje, junto con quién pregunta y desde
 * qué pantalla. La conversación se identifica por persona y compañía: dos
 * personas, o la misma en dos compañías, nunca comparten la memoria del
 * agente.
 */
class ContiChatController extends Controller
{
    public function store(Request $request, CurrentCompany $currentCompany, ContiTokenService $tokens): JsonResponse
    {
        $validated = $request->validate([
            'mensaje' => ['required', 'string', 'max:4000'],
            'sesion' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'pantalla' => ['nullable', 'string', 'max:150'],
        ]);

        $url = config('services.conti.webhook_url');

        if (! $url) {
            return response()->json(['message' => 'Conti todavía no está configurado en esta instalación de CONTAPP.'], 503);
        }

        $companyId = $currentCompany->id();

        if ($companyId === null) {
            return response()->json(['message' => 'Elegí una compañía para hablar con Conti.'], 409);
        }

        $user = $request->user();
        $company = Company::findOrFail($companyId);
        $role = $user->isSuperAdmin($companyId) ? 'Superusuario' : ($user->roleTypeFor($companyId) === 'admin' ? 'Administrador' : 'Usuario');

        $payload = [
            'action' => 'sendMessage',
            'sessionId' => "u{$user->id}-c{$companyId}-{$validated['sesion']}",
            'chatInput' => $validated['mensaje'],
            'metadata' => [
                'contiToken' => $tokens->issue($user, $companyId),
                'usuario' => $user->name,
                'rol' => $role,
                'compania' => $company->trade_name ?: $company->legal_name,
                'pantalla' => $validated['pantalla'] ?? null,
                'fecha' => now()->format('Y-m-d'),
                'modo_gracia' => $currentCompany->isInGracePeriod(),
            ],
        ];

        $timeout = max(10, (int) config('services.conti.timeout', 120));
        // Esperar al agente no consume tiempo de CPU, pero por si acaso.
        @set_time_limit($timeout + 15);

        try {
            $client = Http::timeout($timeout)->acceptJson();

            if (config('services.conti.webhook_user')) {
                $client = $client->withBasicAuth(config('services.conti.webhook_user'), (string) config('services.conti.webhook_password'));
            }

            $response = $client->post($url, $payload);
        } catch (ConnectionException) {
            return response()->json(['message' => 'Conti no está disponible en este momento. Probá de nuevo en unos minutos.'], 502);
        }

        if ($response->failed()) {
            report(new \RuntimeException("Conti: el flujo de n8n respondió {$response->status()}."));

            return response()->json(['message' => 'Conti no pudo responder esta vez. Probá de nuevo; si se repite, avisale al equipo de CONTAPP.'], 502);
        }

        $reply = $this->replyFrom($response);

        if ($reply === '') {
            return response()->json(['message' => 'Conti no devolvió una respuesta. Probá reformular la pregunta.'], 502);
        }

        return response()->json(['respuesta' => $reply]);
    }

    /**
     * La respuesta del agente. El Chat Trigger contesta {"output": "…"} (a
     * veces dentro de una lista), o en modo streaming una línea JSON por
     * pedazo ({"type": "item", "content": "…"}).
     */
    private function replyFrom(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $first = array_is_list($json) ? ($json[0] ?? []) : $json;

            foreach (['output', 'text', 'response', 'message'] as $key) {
                if (is_array($first) && is_string($first[$key] ?? null)) {
                    return trim($first[$key]);
                }
            }
        }

        $body = trim($response->body());
        $streamed = '';

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $chunk = json_decode($line, true);

            if (is_array($chunk) && ($chunk['type'] ?? null) === 'item' && is_string($chunk['content'] ?? null)) {
                $streamed .= $chunk['content'];
            }
        }

        if ($streamed !== '') {
            return trim($streamed);
        }

        return is_array($json) ? '' : $body;
    }
}
