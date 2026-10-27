<?php

namespace App\Domains\Banking\Exceptions;

use RuntimeException;

/**
 * No se pudo consultar el tipo de cambio al BCCR: falta el token, el BCCR lo
 * rechazó, no hay conexión o el servicio respondió con un error. El mensaje
 * dice qué pasó y qué hacer; a la persona se le muestra userMessage(), que en
 * un problema del token no habla del token.
 *
 * Distinto de que el BCCR no tenga dato para una fecha: eso es null en
 * BccrExchangeRateClient::fetchRate().
 */
class BccrUnavailableException extends RuntimeException
{
    /** Lo que ve la persona, cuando no es el mismo mensaje. */
    private ?string $forUsers = null;

    /**
     * Un problema con el token de Pivox (falta, venció, está mal copiado): la
     * persona no lo puede arreglar, porque es uno solo para toda la instalación.
     * Ve un aviso general; el detalle queda en el mensaje, para la salida del
     * comando.
     */
    public static function configuration(string $detail): self
    {
        $exception = new self($detail);
        $exception->forUsers = 'La conexión de Pivox con el BCCR no está funcionando. Avisale al equipo de Pivox.';

        return $exception;
    }

    public function userMessage(): string
    {
        return $this->forUsers ?? $this->getMessage();
    }
}
