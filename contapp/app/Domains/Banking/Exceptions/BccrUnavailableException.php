<?php

namespace App\Domains\Banking\Exceptions;

use RuntimeException;

/**
 * No se pudo consultar el tipo de cambio al BCCR: falta el token, el BCCR lo
 * rechazó, no hay conexión o el servicio respondió con un error. El mensaje
 * es para la persona: dice qué pasó y qué hacer, sin datos de la conexión.
 *
 * Distinto de que el BCCR no tenga dato para una fecha: eso es null en
 * BccrExchangeRateClient::fetchRate().
 */
class BccrUnavailableException extends RuntimeException {}
