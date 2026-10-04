<?php

namespace App\Domains\Feedback\Exceptions;

use RuntimeException;

/**
 * Una acción del canal de comentarios o de las noticias que no se puede
 * hacer: votar una publicación propia, comentar una que ya se resolvió, una
 * imagen que no se pudo leer. El mensaje es para la persona; $field dice
 * junto a qué campo mostrarlo.
 */
class FeedbackException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'body')
    {
        parent::__construct($message);
    }
}
