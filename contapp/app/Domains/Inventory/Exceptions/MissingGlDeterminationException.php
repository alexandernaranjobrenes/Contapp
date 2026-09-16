<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * Ninguna regla de la matriz (ni el tipo de documento como último recurso)
 * resolvió la cuenta de una categoría. Revierte todo el movimiento: un
 * documento logístico sin su asiento no debe existir.
 */
class MissingGlDeterminationException extends \RuntimeException {}
