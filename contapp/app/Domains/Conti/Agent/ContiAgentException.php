<?php

namespace App\Domains\Conti\Agent;

use RuntimeException;

/**
 * El modelo no respondió. Lleva lo que ya se había consumido (para cobrarlo
 * igual: OpenAI lo cobra) y el mensaje que ve la persona, sin detalles
 * técnicos.
 */
final class ContiAgentException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly int $status = 502,
        public readonly ?AgentUsage $usage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function withUsage(AgentUsage $usage): self
    {
        return new self($this->getMessage(), $this->status, $usage, $this->getPrevious());
    }
}
