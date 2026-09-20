<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * La toma física no se puede abrir, capturar o cerrar: almacén inválido, ya
 * hay otra abierta para el mismo almacén, quedan líneas sin contar, o la
 * existencia se movió después de la fecha de corte.
 */
class InvalidStockCountException extends \RuntimeException {}
