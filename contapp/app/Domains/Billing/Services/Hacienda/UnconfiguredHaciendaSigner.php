<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Exceptions\HaciendaNotConfiguredException;

/**
 * Implementación por defecto mientras no haya certificado configurado. Falla
 * de forma explícita en vez de devolver el XML sin firmar: un comprobante que
 * se cree emitido y no lo esté es un problema fiscal, no un detalle técnico.
 */
class UnconfiguredHaciendaSigner implements HaciendaSigner
{
    public function sign(string $xml): string
    {
        throw new HaciendaNotConfiguredException(
            'No hay certificado de firma configurado. Cargá la llave criptográfica de Hacienda y su PIN '.
            'para poder firmar comprobantes; hasta entonces el XML se genera pero no se puede emitir.'
        );
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
