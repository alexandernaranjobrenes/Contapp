<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * El movimiento no es representable: artículo de servicio (sin kardex),
 * almacén inactivo, costo cero en una entrada, o un conteo que no cambia
 * nada. Todos son errores del documento, no del catálogo.
 */
class InvalidStockMovementException extends \RuntimeException {}
