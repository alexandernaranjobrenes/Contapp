<?php

namespace App\Domains\Conti\Agent;

use App\Domains\Conti\Models\ContiAction;

/**
 * La respuesta de Conti y lo que costó. Además del texto puede traer algo
 * para que la persona haga en el chat (un formulario o preguntas con
 * opciones) y lo que quedó preparado para confirmar.
 */
final class AgentReply
{
    /**
     * @param  array{tipo: string}|null  $interaction  ContiToolbox::showForm o ::ask
     * @param  list<ContiAction>  $actions
     */
    public function __construct(
        public readonly string $text,
        public readonly AgentUsage $usage,
        public readonly ?array $interaction = null,
        public readonly array $actions = [],
    ) {}
}
