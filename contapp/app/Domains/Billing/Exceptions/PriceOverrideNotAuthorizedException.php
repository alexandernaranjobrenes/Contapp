<?php

namespace App\Domains\Billing\Exceptions;

/**
 * El cambio de precio no se liberó: falta la autorización, o quien la dio
 * no puede darla.
 */
class PriceOverrideNotAuthorizedException extends \RuntimeException {}
