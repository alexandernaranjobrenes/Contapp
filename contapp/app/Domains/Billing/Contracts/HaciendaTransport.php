<?php

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Models\SalesDocument;

/**
 * Envía el comprobante firmado al API REST de la DGT y consulta su estado.
 * Misma razón que HaciendaSigner para ser interfaz: sin credenciales ATV no
 * hay nada que enviar ni forma de verificar la respuesta.
 */
interface HaciendaTransport
{
    /**
     * @return array{status: string, message: string|null}
     */
    public function send(SalesDocument $document, string $signedXml): array;

    /**
     * @return array{status: string, message: string|null}
     */
    public function status(SalesDocument $document): array;

    public function isConfigured(): bool;
}
