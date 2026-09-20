<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * El documento no se puede anular: ya fue facturado, ya fue anulado, tiene
 * costos de importación aplicados, o la mercancía que trajo ya no está
 * disponible para devolverse al costo con que entró.
 */
class UnvoidableInventoryDocumentException extends \RuntimeException {}
