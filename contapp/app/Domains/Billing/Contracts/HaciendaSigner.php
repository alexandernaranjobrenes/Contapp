<?php

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Services\Hacienda\SigningKey;

/**
 * Firma el XML con XAdES-EPES usando la llave criptográfica que Hacienda
 * entrega al contribuyente.
 *
 * Es una interfaz para que la firma se pueda cambiar (una librería XAdES, un
 * HSM) sin tocar el resto del módulo. La llave no es del firmador sino de
 * cada compañía: se la pasa quien firma, ya abierta (CertificateInspector).
 */
interface HaciendaSigner
{
    public function sign(string $xml, SigningKey $key, ?\DateTimeInterface $signingTime = null): string;
}
