<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * Existencia negativa prohibida (docs/decisiones.md 2026-09-13): sin
 * existencia no hay costo promedio con el que valuar la salida, así que
 * permitirla dejaría el kardex y el mayor apuntando a valores inventados.
 */
class InsufficientStockException extends \RuntimeException {}
