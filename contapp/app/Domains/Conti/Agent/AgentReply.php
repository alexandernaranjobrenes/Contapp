<?php

namespace App\Domains\Conti\Agent;

/** La respuesta de Conti y lo que costó. */
final class AgentReply
{
    public function __construct(
        public readonly string $text,
        public readonly AgentUsage $usage,
    ) {}
}
