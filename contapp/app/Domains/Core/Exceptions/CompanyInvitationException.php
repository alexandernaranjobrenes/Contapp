<?php

namespace App\Domains\Core\Exceptions;

use RuntimeException;

/**
 * Una invitación a una compañía que no se puede hacer o aceptar: la persona
 * ya tiene acceso, la invitación venció, se aceptó con otra cuenta… El
 * mensaje es para la persona; $field dice junto a qué campo mostrarlo.
 */
class CompanyInvitationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'email')
    {
        parent::__construct($message);
    }
}
