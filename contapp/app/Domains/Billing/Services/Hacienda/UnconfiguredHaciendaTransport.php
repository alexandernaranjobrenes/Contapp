<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Exceptions\HaciendaNotConfiguredException;
use App\Domains\Billing\Models\SalesDocument;

class UnconfiguredHaciendaTransport implements HaciendaTransport
{
    public function send(SalesDocument $document, string $signedXml): array
    {
        throw new HaciendaNotConfiguredException(
            'No hay credenciales del API de la DGT configuradas. Registrá el usuario y clave del ATV '.
            'para poder enviar comprobantes.'
        );
    }

    public function status(SalesDocument $document): array
    {
        throw new HaciendaNotConfiguredException('No hay credenciales del API de la DGT configuradas.');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
