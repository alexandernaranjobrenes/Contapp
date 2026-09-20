<?php

namespace App\Domains\Billing\Exceptions;

/**
 * La orden de pedido no se puede registrar, facturar o cancelar: cliente
 * inválido, mercancía no disponible para apartar, o una orden que ya cerró
 * su ciclo.
 */
class InvalidSalesOrderException extends \RuntimeException {}
