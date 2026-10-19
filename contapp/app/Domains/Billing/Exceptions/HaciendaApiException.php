<?php

namespace App\Domains\Billing\Exceptions;

/**
 * Hacienda contestó con error, o no contestó.
 *
 * - transient: no se sabe si el comprobante llegó (tiempo agotado, sin red,
 *   5xx). Antes de reenviar hay que consultar la clave.
 * - status 400: Hacienda no lo recibió, y dice por qué en la cabecera
 *   X-Error-Cause (el cuerpo suele venir vacío).
 * - status 401/403: el token o las credenciales no sirven.
 */
class HaciendaApiException extends \RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCause = null,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly bool $transient = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isUnauthorized(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
