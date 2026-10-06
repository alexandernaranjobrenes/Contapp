<?php

namespace App\Domains\Conti\Actions;

/** Lo que quedó guardado: el mensaje y dónde verlo en CONTAPP. */
final class ActionResult
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly ?string $linkLabel = null,
    ) {}

    public function toArray(): array
    {
        return ['mensaje' => $this->message, 'enlace' => $this->url, 'enlace_texto' => $this->linkLabel];
    }
}
