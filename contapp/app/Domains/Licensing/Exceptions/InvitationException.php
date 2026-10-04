<?php

namespace App\Domains\Licensing\Exceptions;

use RuntimeException;

/**
 * Una asignación de licencia (LicenseInvitationService) que no se puede
 * hacer, cambiar o aceptar. El mensaje está escrito para mostrarse tal cual,
 * y `field` dice junto a qué campo del formulario va.
 */
class InvitationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'invitation')
    {
        parent::__construct($message);
    }
}
