<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * La orden de fabricación no admite la operación: ya está cerrada, no tiene
 * costo acumulado que descargar, o la cantidad producida no es válida.
 */
class InvalidProductionException extends \RuntimeException {}
