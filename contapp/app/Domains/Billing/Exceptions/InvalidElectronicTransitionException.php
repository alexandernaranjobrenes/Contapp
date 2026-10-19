<?php

namespace App\Domains\Billing\Exceptions;

/**
 * Un cambio de estado de un comprobante electrónico que la norma no admite
 * (reenviar uno aceptado, sustituir uno que Hacienda todavía procesa…). Se
 * lanza en vez de ignorarse: si la cola llega a intentarlo, tiene que verse.
 */
class InvalidElectronicTransitionException extends \RuntimeException {}
