<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * La devolución no se puede registrar: la recepción no es una compra, no está
 * facturada todavía, o se está devolviendo más de lo que entró.
 */
class InvalidPurchaseReturnException extends \RuntimeException {}
