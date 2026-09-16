<?php

namespace App\Domains\Billing\Exceptions;

/**
 * Falta el certificado de firma o las credenciales del API de la DGT. No es un
 * error del comprobante: el documento y su XML son válidos, lo que falta es la
 * configuración para firmarlo y enviarlo.
 */
class HaciendaNotConfiguredException extends \RuntimeException {}
