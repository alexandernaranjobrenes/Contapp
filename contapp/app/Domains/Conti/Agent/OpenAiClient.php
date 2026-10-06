<?php

namespace App\Domains\Conti\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Las llamadas a OpenAI: Chat Completions y la lista de modelos. Nada más:
 * el ciclo con las herramientas lo lleva ContiAgent. Los errores se traducen
 * a lo que la persona puede entender, y el detalle técnico va al log.
 */
class OpenAiClient
{
    /**
     * Los modelos que la key puede usar (GET /models: no consume tokens).
     * Null si no se pudo saber: sin conexión, o OpenAI respondió con error.
     *
     * @return list<string>|null
     */
    public function models(): ?array
    {
        try {
            $response = $this->request(15)->get($this->url('/models'));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            report(new \RuntimeException("Conti: OpenAI respondió {$response->status()} al pedir los modelos."));

            return null;
        }

        return collect($response->json('data', []))->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
    }

    public function chat(array $payload): array
    {
        $request = $this->request(max(10, (int) config('conti.request_timeout', 60)));

        try {
            $response = $request->post($this->url('/chat/completions'), $payload);
        } catch (ConnectionException $e) {
            throw new ContiAgentException('Conti no está disponible en este momento. Probá de nuevo en unos minutos.', 503, null, $e);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $detail = $response->json('error.message') ?? $response->body();
        report(new \RuntimeException("Conti: OpenAI respondió {$response->status()}: ".mb_substr((string) $detail, 0, 500)));

        throw match (true) {
            $response->status() === 401 => new ContiAgentException('Conti no está bien configurado (la key de OpenAI no es válida). Avisale al equipo de CONTAPP.', 503),
            $response->status() === 429 => new ContiAgentException('Conti está recibiendo demasiadas consultas o se agotó el saldo del servicio de IA. Probá de nuevo en unos minutos; si se repite, avisale al equipo de CONTAPP.', 503),
            $response->status() === 404 => new ContiAgentException('El modelo de IA elegido no está disponible. Elegí otro en «Modelo y consumo», arriba en el chat; si se repite, avisale al equipo de CONTAPP.', 503),
            default => new ContiAgentException('Conti no pudo responder esta vez. Probá de nuevo; si se repite, avisale al equipo de CONTAPP.', 502),
        };
    }

    private function request(int $timeout): PendingRequest
    {
        $request = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout($timeout);

        if ($organization = config('services.openai.organization')) {
            $request = $request->withHeaders(['OpenAI-Organization' => $organization]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.openai.base_url'), '/').$path;
    }
}
