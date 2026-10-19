<?php

namespace App\Domains\Billing\Exceptions;

/**
 * El comprobante no se puede emitir electrónicamente tal como está: falta un
 * dato del emisor o del receptor, el XML no pasa el esquema oficial, o la
 * llave no sirve para firmarlo. Hereda de InvalidSalesDocumentException a
 * propósito: se lanza dentro de la transacción de la venta, y así la deshace
 * entera (no queda una venta registrada con un comprobante que no se puede
 * enviar ni un consecutivo quemado).
 */
class ElectronicInvoicingException extends InvalidSalesDocumentException {}
