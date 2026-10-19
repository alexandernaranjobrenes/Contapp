<?php

namespace App\Domains\Billing\Exceptions;

/**
 * La llave criptográfica no se pudo usar: PIN incorrecto, archivo dañado,
 * vencida, o de otro contribuyente. Se detecta al cargarla, no al facturar.
 */
class CertificateException extends \RuntimeException {}
