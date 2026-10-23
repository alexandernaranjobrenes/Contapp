<?php

namespace App\Domains\Repository\Exceptions;

use RuntimeException;

/**
 * Algo del Repositorio que no se puede hacer: un archivo que no es texto, una
 * carpeta con documentos que se quiere eliminar. El mensaje es para la
 * persona; $field dice junto a qué campo mostrarlo.
 */
class RepositoryException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'file')
    {
        parent::__construct($message);
    }
}
