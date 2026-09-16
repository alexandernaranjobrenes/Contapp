<?php

namespace App\Domains\Billing\Exceptions;

/**
 * El comprobante no es emitible: falta un dato que la norma exige, los medios
 * de pago no cuadran con el total, o una combinación de códigos que Hacienda
 * rechazaría. Revierte toda la transacción — sin XML válido no debe quedar ni
 * movimiento de inventario ni asiento.
 */
class InvalidSalesDocumentException extends \RuntimeException {}
