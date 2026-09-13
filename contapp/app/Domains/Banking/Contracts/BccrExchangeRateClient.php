<?php

namespace App\Domains\Banking\Contracts;

interface BccrExchangeRateClient
{
    /**
     * @return string|null Tipo de cambio como string decimal (ej. "520.500000"),
     *                      o null si el BCCR no publica dato para esa fecha
     *                      (fin de semana, feriado, o error de comunicación).
     */
    public function fetchRate(string $currencyCode, \DateTimeInterface $date): ?string;
}
