<?php

namespace App\Domains\Banking\Contracts;

use App\Domains\Banking\Exceptions\BccrUnavailableException;

interface BccrExchangeRateClient
{
    /**
     * @return string|null Tipo de cambio como string decimal (ej. "520.500000"),
     *                     o null si el BCCR no tiene dato de esa moneda para
     *                     esa fecha (todavía no lo publica, u otra moneda).
     *
     * @throws BccrUnavailableException si no se pudo consultar: sin token,
     *                                  token rechazado, sin conexión o error
     *                                  del servicio.
     */
    public function fetchRate(string $currencyCode, \DateTimeInterface $date): ?string;
}
