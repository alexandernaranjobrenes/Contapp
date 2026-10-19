<?php

namespace App\Domains\Billing\Services\Hacienda;

/**
 * La llave privada y el certificado ya abiertos, en memoria. Existe solo
 * mientras se firma: no se guarda ni se serializa.
 */
final class SigningKey
{
    public function __construct(
        public readonly \OpenSSLAsymmetricKey $privateKey,
        public readonly string $certificatePem,
    ) {}

    public function __serialize(): array
    {
        throw new \LogicException('Una llave de firma no se serializa.');
    }
}
