<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * El costo de importación no se puede aplicar: la recepción no es una entrada
 * de mercancía, está anulada, no tiene líneas, o el monto no es válido.
 */
class InvalidLandedCostException extends \RuntimeException {}
