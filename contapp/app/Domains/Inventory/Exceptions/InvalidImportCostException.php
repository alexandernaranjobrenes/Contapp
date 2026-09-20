<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * El rubro de importación no se puede acumular, asignar o cancelar: concepto
 * desconocido, proveedor inválido, monto que excede lo pendiente por asignar,
 * o un rubro que ya entró al costo del inventario.
 */
class InvalidImportCostException extends \RuntimeException {}
