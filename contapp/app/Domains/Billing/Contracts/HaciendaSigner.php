<?php

namespace App\Domains\Billing\Contracts;

/**
 * Firma el XML con XAdES-EPES usando la llave criptográfica que Hacienda
 * entrega al contribuyente.
 *
 * Es una interfaz y no una clase concreta porque firmar exige un certificado
 * real: sin él no hay forma de construir ni de verificar una firma, y una
 * implementación "de prueba" que devolviera el XML sin firmar sería peor que
 * no tener ninguna — daría por emitido un comprobante que la DGT rechazaría.
 */
interface HaciendaSigner
{
    public function sign(string $xml): string;

    public function isConfigured(): bool;
}
