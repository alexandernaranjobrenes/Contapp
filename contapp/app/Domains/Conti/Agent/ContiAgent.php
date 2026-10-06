<?php

namespace App\Domains\Conti\Agent;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Conti\Actions\ContiAction;
use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Reports\ContiReport;
use App\Domains\Conti\Reports\ContiReportCatalog;
use App\Domains\Conti\Resources\ContiResource;
use App\Domains\Conti\Resources\ContiResourceCatalog;
use App\Domains\Conti\Services\ContiModelService;
use App\Domains\Conti\Support\ContiContext;

/**
 * Conti: el ciclo con el modelo de OpenAI (CLAUDE.md secc. 32).
 *
 * 1. Arma el mensaje de sistema: las instrucciones (resources/conti/
 *    instrucciones.md), quién pregunta y desde dónde, lo que puede consultar
 *    según sus permisos, y la lista de temas del manual.
 * 2. Le pasa la conversación y las herramientas (ContiToolbox). Si el
 *    modelo pide herramientas, se ejecutan acá mismo y se le devuelve el
 *    resultado; así hasta que responde, o hasta conti.max_iterations (la
 *    última vuelta ya no ofrece herramientas: tiene que responder).
 * 3. Suma lo consumido en todas las llamadas (AgentUsage), para cobrarlo en
 *    créditos.
 *
 * El modelo es el que eligió la persona (ContiModelService). A los que razonan
 * se les pide un razonamiento corto y se les deja escribir más: lo que
 * piensan cuenta como salida.
 */
class ContiAgent
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly ContiToolbox $tools,
        private readonly ContiContext $context,
        private readonly ContiManual $manual,
        private readonly ContiModelService $models,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  lo hablado antes (solo textos)
     * @param  string|null  $model  el de la persona; si no, el predeterminado
     */
    public function reply(array $history, string $message, ?string $screen = null, ?string $model = null): AgentReply
    {
        $model ??= $this->models->default();
        $reasons = $this->models->reasons($model);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($screen)],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ];

        $usage = new AgentUsage($model);
        $maxIterations = max(1, (int) config('conti.max_iterations', 6));

        try {
            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                $payload = [
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $this->tools->definitions(),
                    'tool_choice' => $iteration === $maxIterations ? 'none' : 'auto',
                    'max_completion_tokens' => $reasons
                        ? (int) config('conti.max_output_tokens_reasoning', 4000)
                        : (int) config('conti.max_output_tokens', 1500),
                ];

                if ($reasons) {
                    $payload['reasoning_effort'] = (string) config('conti.reasoning_effort', 'low');
                }

                $response = $this->client->chat($payload);

                $usage->add($response);
                $answer = $response['choices'][0]['message'] ?? [];
                $calls = $answer['tool_calls'] ?? [];

                if ($calls === []) {
                    $text = $this->tidyLinks(trim((string) ($answer['content'] ?? '')));

                    return new AgentReply(
                        $text !== '' ? $text : 'No tengo una respuesta para eso. ¿Podés decirlo de otra forma?',
                        $usage,
                    );
                }

                $messages[] = ['role' => 'assistant', 'content' => $answer['content'] ?? null, 'tool_calls' => $calls];

                foreach ($calls as $call) {
                    $usage->toolCalls++;
                    $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);

                    $result = is_array($arguments)
                        ? $this->tools->run((string) ($call['function']['name'] ?? ''), $arguments)
                        : ['error' => 'Los parámetros no son un JSON válido.', 'codigo' => 422];

                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => $this->encode($result)];
                }
            }
        } catch (ContiAgentException $e) {
            throw $e->withUsage($usage);
        }

        return new AgentReply('Necesité demasiados pasos para responder eso. ¿Podés hacer la pregunta más concreta?', $usage);
    }

    /**
     * El enlace para confirmar lo que Conti preparó es una ruta de CONTAPP
     * («/conti/acciones/…»), y así tiene que llegar al chat. El modelo a
     * veces le inventa un dominio («https://app.contapp.run/conti/…»): con
     * eso el enlace se abre en otra pestaña, contra un sitio que no existe.
     */
    public function tidyLinks(string $text): string
    {
        return (string) preg_replace(
            '~(?:https?://)?[A-Za-z0-9.-]+(?::\d+)?(/conti/acciones/[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})~',
            '$1',
            $text,
        );
    }

    public function systemPrompt(?string $screen): string
    {
        $user = $this->context->user();
        $company = $this->context->company();
        $local = Currency::find($company->local_currency_id)?->code;
        $foreign = $company->foreign_currency_id ? Currency::find($company->foreign_currency_id)?->code : null;

        $resources = array_map(fn (ContiResource $r) => "{$r->key} ({$r->label})", ContiResourceCatalog::availableTo($this->context));
        $reports = array_map(fn (ContiReport $r) => "{$r->key} ({$r->label})", ContiReportCatalog::availableTo($this->context));
        $actions = $this->context->inGrace() ? [] : array_map(fn (ContiAction $a) => "{$a->key()} ({$a->label()})", ContiActionCatalog::availableTo($this->context));

        $none = 'ninguna con sus permisos actuales';

        return implode("\n\n", [
            (string) file_get_contents(resource_path('conti/instrucciones.md')),
            implode("\n", [
                '## Esta conversación',
                "- Persona: {$user->name} ({$this->context->roleLabel()}).",
                '- Compañía activa: '.($company->trade_name ?: $company->legal_name)." (moneda local {$local}".($foreign ? ", extranjera {$foreign}" : '').').',
                '- Pantalla en la que está: '.($screen ?: 'no se sabe').'. Viene con el título y, entre corchetes, el nombre interno de la ruta: usalo para entender de qué campo o botón te hablan.',
                '- Fecha de hoy: '.now(config('conti.timezone'))->format('Y-m-d').'.',
                $this->context->inGrace()
                    ? '- La licencia está vencida (modo de gracia): se puede consultar, pero no preparar nada para guardar.'
                    : '- La licencia está vigente.',
            ]),
            "## Lo que esta persona puede usar\n"
                .'- Consultas: '.($resources ? implode(', ', $resources) : $none).".\n"
                .'- Reportes: '.($reports ? implode(', ', $reports) : $none).".\n"
                .'- Acciones para preparar: '.($actions ? implode(', ', $actions) : $none).'.',
            "## Temas del manual (herramienta «manual»)\n".$this->manual->tableOfContents(),
        ]);
    }

    /**
     * El resultado de una herramienta para el modelo. Si es muy largo, se
     * recortan sus filas (y se le dice que filtre) en vez de cortar el JSON
     * por la mitad.
     */
    private function encode(array $result): string
    {
        $max = (int) config('conti.max_tool_result_chars', 24000);
        $json = $this->json($result);

        if (strlen($json) <= $max) {
            return $json;
        }

        foreach ([['registros'], ['resultado', 'filas']] as $path) {
            $rows = data_get($result, implode('.', $path));

            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }

            $total = count($rows);
            $keep = $total;

            while ($keep > 1 && strlen($json) > $max) {
                $keep = intdiv($keep, 2);
                data_set($result, implode('.', $path), array_slice($rows, 0, $keep));
                $result['nota_recorte'] = "Se muestran {$keep} de {$total} filas porque el resultado era muy largo: filtrá o pedí menos.";
                $json = $this->json($result);
            }

            if (strlen($json) <= $max) {
                return $json;
            }
        }

        return mb_strcut($json, 0, $max).'… (recortado: el resultado era muy largo; filtrá o pedí menos)';
    }

    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
