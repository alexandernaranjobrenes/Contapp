<?php

namespace App\Domains\Blog\Exceptions;

use RuntimeException;

/**
 * Algo que no se pudo hacer en el blog, dicho para quien escribe el
 * artículo, con el campo al que corresponde.
 */
class BlogException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'blog')
    {
        parent::__construct($message);
    }
}
