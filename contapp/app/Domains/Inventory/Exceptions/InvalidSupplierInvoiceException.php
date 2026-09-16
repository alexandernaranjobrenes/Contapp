<?php

namespace App\Domains\Inventory\Exceptions;

/**
 * La factura no puede liquidar esa recepción: no es una entrada por compra,
 * está anulada, ya fue facturada, o el impuesto que trae no es representable.
 */
class InvalidSupplierInvoiceException extends \RuntimeException {}
