<?php

namespace App\Domains\Billing\Services\Hacienda;

/**
 * Lo que contestó el API de recepción, ya leído. Las cabeceras que se guardan
 * son las que sirven para diagnosticar; nunca la de autorización.
 */
final class HaciendaResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?array $json,
        public readonly ?string $errorCause,
        public readonly int $durationMs,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    /** ind-estado en minúsculas: recibido, procesando, aceptado, rechazado, error. */
    public function indicator(): ?string
    {
        $value = $this->json['ind-estado'] ?? $this->json['indEstado'] ?? $this->json['estado'] ?? null;

        return is_string($value) ? mb_strtolower(trim($value)) : null;
    }

    /** El MensajeHacienda, decodificado de respuesta-xml. */
    public function responseXml(): ?string
    {
        $encoded = $this->json['respuesta-xml'] ?? $this->json['respuestaXml'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }
}
