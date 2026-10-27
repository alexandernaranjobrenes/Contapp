<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Exceptions\HaciendaApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del API de recepción (config/hacienda.php, por ambiente).
 *
 * Lo que ya se aprendió contra el sandbox (guía, L18): ante un 400 Hacienda
 * suele mandar el cuerpo vacío y la causa en la cabecera X-Error-Cause. Esa
 * es la que se lee y se muestra.
 */
class HaciendaApiClient implements HaciendaTransport
{
    /** Las cabeceras que sirven para diagnosticar. Ninguna lleva secretos. */
    private const KEPT_HEADERS = ['x-error-cause', 'location', 'x-ratelimit-remaining', 'date', 'content-type', 'x-request-id'];

    public function submit(string $environment, string $accessToken, array $payload): HaciendaResponse
    {
        $response = $this->call(fn () => $this->request($accessToken)
            ->post($this->apiUrl($environment).'/recepcion', $payload));

        if ($response->successful()) {
            return $response;
        }

        throw $this->failure('Hacienda no recibió el comprobante', $response);
    }

    public function status(string $environment, string $accessToken, string $clave): HaciendaResponse
    {
        if (! preg_match('/^\d{50}$/', $clave)) {
            throw new HaciendaApiException('La clave a consultar no tiene 50 dígitos.');
        }

        $response = $this->call(fn () => $this->request($accessToken)
            ->get($this->apiUrl($environment).'/recepcion/'.$clave));

        if ($response->successful() || $response->notFound()) {
            return $response;
        }

        throw $this->failure('Hacienda no contestó la consulta', $response);
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('hacienda.timeout'))
            ->connectTimeout((int) config('hacienda.connect_timeout'));
    }

    /**
     * @param  callable(): Response  $send
     */
    private function call(callable $send): HaciendaResponse
    {
        $started = hrtime(true);

        try {
            $response = $send();
        } catch (ConnectionException $e) {
            // El detalle de la conexión (el error de cURL) queda en el registro:
            // el historial del comprobante lo lee quien factura.
            Log::warning('Hacienda: sin conexión.', ['message' => mb_substr($e->getMessage(), 0, 500)]);

            throw new HaciendaApiException(
                'No se pudo conectar con Hacienda: no respondió a tiempo o no hay conexión.',
                transient: true,
                previous: $e,
            );
        }

        $headers = [];

        foreach (self::KEPT_HEADERS as $name) {
            if ($response->header($name) !== '') {
                $headers[$name] = mb_substr($response->header($name), 0, 1000);
            }
        }

        $body = (string) $response->body();

        return new HaciendaResponse(
            status: $response->status(),
            headers: $headers,
            body: mb_substr($body, 0, 20000),
            json: is_array($decoded = json_decode($body, true)) ? $decoded : null,
            errorCause: $headers['x-error-cause'] ?? null,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }

    private function failure(string $prefix, HaciendaResponse $response): HaciendaApiException
    {
        $cause = $response->errorCause
            ?? $response->json['message'] ?? $response->json['error_description'] ?? $response->json['error'] ?? $response->json['mensaje'] ?? null;

        if (! is_string($cause) || trim($cause) === '') {
            $cause = trim(mb_substr($response->body, 0, 300)) ?: null;
        }

        return new HaciendaApiException(
            // El código HTTP no va en el mensaje (lo lee quien factura): queda
            // en `status` y en el intercambio guardado.
            $prefix.($cause ? ': '.$cause : '.'),
            status: $response->status,
            errorCause: $cause,
            headers: $response->headers,
            body: $response->body,
            // 5xx y 429: Hacienda no pudo atender; el comprobante pudo o no
            // haber quedado. Se consulta antes de reintentar.
            transient: $response->status >= 500 || $response->status === 429 || $response->status === 408,
        );
    }

    private function apiUrl(string $environment): string
    {
        $url = config("hacienda.environments.{$environment}.api_url")
            ?? throw new HaciendaApiException("Ambiente de Hacienda desconocido: {$environment}.");

        return rtrim($url, '/');
    }
}
