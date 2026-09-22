<?php

namespace App\Domains\Inventory\Exceptions;

use RuntimeException;

/**
 * Una receta que no describe algo fabricable: se contiene a sí misma, rinde
 * cero, o lleva un componente que no puede consumirse.
 */
class InvalidBillOfMaterialException extends RuntimeException {}
