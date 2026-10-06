<?php

namespace App\Domains\Conti\Agent;

/** Lo que consumió una respuesta de Conti: todas las llamadas al modelo, sumadas. */
final class AgentUsage
{
    public function __construct(
        public string $model = '',
        public int $promptTokens = 0,
        public int $cachedTokens = 0,
        public int $completionTokens = 0,
        public int $toolCalls = 0,
        public int $calls = 0,
    ) {}

    /** Suma el «usage» de una respuesta de OpenAI. */
    public function add(array $response): void
    {
        $usage = $response['usage'] ?? [];

        $this->model = (string) ($response['model'] ?? $this->model);
        $this->promptTokens += (int) ($usage['prompt_tokens'] ?? 0);
        $this->cachedTokens += (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $this->completionTokens += (int) ($usage['completion_tokens'] ?? 0);
        $this->calls++;
    }

    public function isEmpty(): bool
    {
        return $this->promptTokens === 0 && $this->completionTokens === 0;
    }
}
