<?php

namespace App\Domains\Conti\Agent;

use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Conti\Reports\ContiReportCatalog;
use App\Domains\Conti\Resources\ContiResource;
use App\Domains\Conti\Resources\ContiResourceCatalog;
use App\Domains\Conti\Services\ContiActionService;
use App\Domains\Conti\Services\ContiFormService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiRedactor;
use App\Domains\Core\Support\ScreenCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Las herramientas que el modelo puede usar (CLAUDE.md secc. 32). Todas
 * corren a nombre de la persona del ContiContext, con sus permisos por
 * pantalla y en su compañía.
 *
 * Nunca lanzan: un error vuelve como dato ({error, codigo}) para que el
 * modelo se lo explique a la persona —«no tenés permiso para…», «falta tal
 * dato»—. Todo lo que devuelven pasa por ContiRedactor.
 *
 * «formulario» y «preguntar» no le devuelven datos al modelo: terminan su
 * turno con algo para la persona (un formulario o preguntas con opciones,
 * {interaccion}), que el chat le muestra. Lo que la persona conteste o envíe
 * llega después.
 */
class ContiToolbox
{
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    /** Los parámetros de una consulta que no son sus filtros. */
    private const RESERVED = ['buscar', 'desde', 'hasta', 'limite', 'pagina', 'id'];

    /** @var list<ContiAction> lo que se preparó en esta respuesta */
    private array $prepared = [];

    public function __construct(
        private readonly ContiContext $context,
        private readonly ContiActionService $actions,
        private readonly ContiManual $manual,
        private readonly ContiFormService $forms,
    ) {}

    /** Lo que se preparó en esta respuesta: CONTAPP le abre la ventana para confirmarlo. */
    public function prepared(): array
    {
        return $this->prepared;
    }

    /** Las herramientas, en el formato de «tools» de OpenAI. */
    public function definitions(): array
    {
        $tool = fn (string $name, string $description, array $properties, array $required = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
            ],
        ];

        return [
            $tool('manual', 'Busca en el manual de CONTAPP: cómo se hace algo, qué significa un campo, un estado o un mensaje de error. Usala antes de explicar cualquier cosa de la aplicación. Si una sección trae «sin_acceso», la persona no puede entrar ahí: decíselo primero.', [
                'tema' => ['type' => 'string', 'description' => 'Palabras clave del tema, ej. «anular asiento» o «aguinaldo liquidación».'],
            ], ['tema']),
            $tool('contexto', 'Con ver y clave: el detalle de una consulta (filtros), un reporte (parámetros) o una acción (datos que pide). Sin parámetros: los permisos de la persona por pantalla.', [
                'ver' => ['type' => 'string', 'enum' => ['consulta', 'reporte', 'accion']],
                'clave' => ['type' => 'string', 'description' => 'La clave de la consulta, el reporte o la acción.'],
            ]),
            $tool('consultar', 'Registros de una consulta de la compañía (socios, asientos, partidas-abiertas, articulos…), con búsqueda, filtros y páginas. Con id, el detalle de un registro.', [
                'consulta' => ['type' => 'string', 'description' => 'La clave de la consulta, ej. socios.'],
                'parametros' => ['type' => 'object', 'description' => 'Opcionales: buscar, desde y hasta (AAAA-MM-DD), limite (hasta 100), pagina, id (id o código) y los filtros propios de la consulta.'],
            ], ['consulta']),
            $tool('reporte', 'Corre un reporte de CONTAPP con los mismos números que su pantalla (balance-comprobacion, estado-resultados, mayor, antiguedad-saldos…).', [
                'reporte' => ['type' => 'string', 'description' => 'La clave del reporte.'],
                'parametros' => ['type' => 'object', 'description' => 'Los parámetros del reporte; vacío para los valores por defecto.'],
            ], ['reporte']),
            $tool('formulario', 'Muestra en el chat el formulario de una acción para registrar o editar. Es TU PRIMERA RESPUESTA siempre que la persona pida registrar, crear, agregar, anotar, cambiar o editar algo, aunque no te haya dado ningún dato: el formulario es la forma de pedírselos. Nunca le pidas los datos por mensaje. Precargá lo que sepas; CONTAPP agrega sugerencias según cómo se viene trabajando. La persona lo completa y lo envía ahí mismo, y CONTAPP le abre la ventana para confirmar. Termina tu turno.', [
                'accion' => ['type' => 'string', 'description' => 'La clave de la acción, ej. crear_socio.'],
                'datos' => ['type' => 'object', 'description' => 'Lo que ya sabés, con los nombres de campo de la acción. Solo lo que dijo la persona o consultaste; nunca inventes.'],
            ], ['accion']),
            $tool('preparar_accion', 'Prepara algo para guardar, cuando ya tenés todos los datos. No guarda: CONTAPP le abre a la persona la ventana para revisarlo y confirmarlo.', [
                'accion' => ['type' => 'string', 'description' => 'La clave de la acción, ej. crear_socio.'],
                'datos' => ['type' => 'object', 'description' => 'Los datos que pide la acción.'],
            ], ['accion', 'datos']),
            $tool('preguntar', 'Le hace a la persona de 1 a 4 preguntas con opciones para elegir (una o varias), como botones en el chat; siempre puede escribir otra respuesta. Terminá tu turno con esto cuando necesites que elija para seguir. La respuesta llega como su próximo mensaje.', [
                'preguntas' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'pregunta' => ['type' => 'string', 'description' => 'La pregunta, clara y corta, terminada en «?».'],
                        'encabezado' => ['type' => 'string', 'description' => 'Una o dos palabras, ej. «Período».'],
                        'multiple' => ['type' => 'boolean', 'description' => 'true si puede elegir varias.'],
                        'opciones' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'etiqueta' => ['type' => 'string', 'description' => 'La opción, en pocas palabras.'],
                                'descripcion' => ['type' => 'string', 'description' => 'Qué implica, opcional.'],
                            ],
                            'required' => ['etiqueta'],
                        ]],
                    ],
                    'required' => ['pregunta', 'opciones'],
                ]],
            ], ['preguntas']),
            $tool('estado_accion', 'En qué quedó una acción preparada: pendiente, guardado, descartado, no se pudo guardar o vencido.', [
                'id' => ['type' => 'string', 'description' => 'El id de la acción preparada.'],
            ], ['id']),
        ];
    }

    /** Corre una herramienta. Nunca lanza: un error vuelve como {error, codigo}. */
    public function run(string $name, array $arguments): array
    {
        try {
            $result = match ($name) {
                'manual' => $this->manualFor((string) ($arguments['tema'] ?? '')),
                'contexto' => $this->context($arguments['ver'] ?? null, $arguments['clave'] ?? null),
                'consultar' => $this->records((string) ($arguments['consulta'] ?? ''), (array) ($arguments['parametros'] ?? [])),
                'reporte' => $this->report((string) ($arguments['reporte'] ?? ''), (array) ($arguments['parametros'] ?? [])),
                'preparar_accion' => $this->prepareAction((string) ($arguments['accion'] ?? ''), (array) ($arguments['datos'] ?? [])),
                'formulario' => $this->showForm((string) ($arguments['accion'] ?? ''), (array) ($arguments['datos'] ?? [])),
                'preguntar' => $this->ask((array) ($arguments['preguntas'] ?? [])),
                'estado_accion' => $this->actionStatus((string) ($arguments['id'] ?? '')),
                default => ['error' => "No existe la herramienta «{$name}».", 'codigo' => 404],
            };
        } catch (ValidationException $e) {
            return ['error' => 'Hay datos que no sirven: '.collect($e->errors())->flatten()->implode(' '), 'codigo' => 422, 'detalle' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            return ['error' => $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo.', 'codigo' => $e->getStatusCode()];
        } catch (ModelNotFoundException) {
            return ['error' => 'No encontré eso en esta compañía.', 'codigo' => 404];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Esa consulta falló por un error interno. Si se repite, hay que avisarle al equipo de CONTAPP.', 'codigo' => 500];
        }

        // Lo que es para la persona (un formulario, preguntas) no lo ve el
        // modelo: va directo al chat.
        return isset($result['interaccion']) ? $result : ContiRedactor::clean($result);
    }

    /**
     * El manual, con un aviso en cada sección que trata de una pantalla a la
     * que la persona no tiene acceso: así Conti no la guía por donde no puede
     * entrar ni le pide datos para algo que no puede hacer.
     */
    public function manualFor(string $topic): array
    {
        $result = $this->manual->search($topic);

        if (! isset($result['secciones']) || $this->context->isSuperAdmin()) {
            return $result;
        }

        $levels = $this->context->levels();
        $screens = [];
        $sections = [];

        foreach (ScreenCatalog::sections() as $section) {
            $missing = array_filter($section['screens'], fn (array $screen) => ($levels[$screen['key']] ?? 'none') === 'none');

            foreach ($missing as $screen) {
                $screens[] = "{$section['label']} → {$screen['label']}";
            }

            if (count($missing) === count($section['screens'])) {
                $sections[] = $section['label'];
            }
        }

        foreach ($result['secciones'] as &$found) {
            $text = $found['seccion'].' '.$found['texto'];
            // El capítulo del manual («8. Planillas (Costa Rica) › …»).
            $chapter = preg_replace('/^\d+\.\s*/', '', explode(' › ', $found['seccion'])[0]);
            $noModule = array_values(array_filter($sections, fn (string $label) => str_starts_with($chapter, $label)));
            $noScreen = array_values(array_filter($screens, fn (string $path) => str_contains($text, $path)));

            if ($noModule !== [] || $noScreen !== []) {
                $found['sin_acceso'] = 'La persona NO tiene acceso a '
                    .($noModule !== [] ? 'ninguna pantalla de '.implode(', ', $noModule) : implode(', ', $noScreen))
                    .'. Decíselo antes de explicar cómo se hace, sin pedirle datos, y que se lo pida al Superusuario o a un Administrador.';
            }
        }
        unset($found);

        return $result;
    }

    /** Los permisos de la persona, o el detalle de una consulta, un reporte o una acción. */
    public function context(?string $kind, ?string $key): array
    {
        if ($kind === null || $kind === '') {
            $screens = [];
            foreach ($this->context->levels() as $screen => $level) {
                if ($level !== 'none') {
                    $screens[] = ContiContext::screenPath($screen).($level === 'read_write' ? ' (lectura y escritura)' : ' (lectura)');
                }
            }

            return ['rol' => $this->context->roleLabel(), 'permisos' => $screens];
        }

        $key = (string) $key;

        return match ($kind) {
            'consulta' => $this->describeResource($key),
            'reporte' => $this->describeReport($key),
            'accion' => $this->describeAction($key),
            default => abort(422, 'ver es consulta, reporte o accion.'),
        };
    }

    /** Los registros de una consulta, o el detalle de uno (con id). */
    public function records(string $resource, array $params): array
    {
        $definition = ContiResourceCatalog::find($resource)
            ?? abort(404, "No existe la consulta «{$resource}». Las disponibles están en la lista de tus instrucciones.");

        if ($definition->screens !== []) {
            $this->context->authorize($definition->screens);
        }

        $params = $this->scalars($params);

        $validated = Validator::make($params, [
            'buscar' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'id' => ['nullable', 'string', 'max:100'],
        ])->validate();

        if (filled($validated['id'] ?? null)) {
            return $this->detail($definition, (string) $validated['id']);
        }

        $query = $definition->newQuery();
        $filters = array_diff_key($params, array_flip(self::RESERVED));
        $unknown = array_diff(array_keys($filters), array_keys($definition->filters));

        if ($unknown !== []) {
            abort(422, 'Filtros que esta consulta no tiene: '.implode(', ', $unknown).'. Los que acepta: '
                .(implode(', ', array_keys($definition->filters)) ?: 'ninguno').'.');
        }

        if ($definition->defaultFilter) {
            ($definition->defaultFilter)($query, $filters);
        }

        foreach ($filters as $name => $value) {
            if ($value !== '') {
                ($definition->filters[$name][1])($query, trim($value));
            }
        }

        if (filled($validated['buscar'] ?? null)) {
            $this->applySearch($definition, $query, trim($validated['buscar']));
        }

        if ($definition->dateColumn) {
            if (filled($validated['desde'] ?? null)) {
                $query->whereDate($definition->dateColumn, '>=', $validated['desde']);
            }
            if (filled($validated['hasta'] ?? null)) {
                $query->whereDate($definition->dateColumn, '<=', $validated['hasta']);
            }
        }

        $limit = (int) ($validated['limite'] ?? self::DEFAULT_LIMIT);
        $page = (int) ($validated['pagina'] ?? 1);
        $total = (clone $query)->toBase()->getCountForPagination();

        foreach ($definition->orderBy as $column => $direction) {
            $query->orderBy($query->getModel()->qualifyColumn($column), $direction);
        }

        return [
            'consulta' => $definition->key,
            'nombre' => $definition->label,
            'total' => $total,
            'pagina' => $page,
            'limite' => $limit,
            'hay_mas' => $page * $limit < $total,
            'registros' => $query->forPage($page, $limit)->get()->map(fn ($model) => $definition->present($model))->all(),
        ];
    }

    /** Corre un reporte con los mismos servicios que su pantalla. */
    public function report(string $report, array $params): array
    {
        $definition = ContiReportCatalog::find($report)
            ?? abort(404, "No existe el reporte «{$report}». Los disponibles están en la lista de tus instrucciones.");

        $this->context->authorize($definition->screens);

        $params = array_filter($this->scalars($params), fn ($value) => $value !== '');
        $unknown = array_diff(array_keys($params), array_keys($definition->params));

        if ($unknown !== []) {
            abort(422, 'Parámetros que este reporte no tiene: '.implode(', ', $unknown).'. Los que acepta: '
                .(implode(', ', array_keys($definition->params)) ?: 'ninguno').'.');
        }

        return [
            'reporte' => $definition->key,
            'nombre' => $definition->label,
            'parametros' => $params,
            'resultado' => ($definition->run)($params),
        ];
    }

    /** Deja algo listo para que la persona lo confirme: CONTAPP le abre la ventana. */
    public function prepareAction(string $action, array $data): array
    {
        $pending = $this->actions->prepare($this->context, $action, $data);
        $this->prepared[] = $pending;

        return [
            ...ContiActionService::present($pending),
            'mensaje' => 'Listo para confirmar. Todavía no se guardó nada: CONTAPP ya le abrió a la persona la ventana con el resumen, '
                .'donde toca «Confirmar y guardar» o «Corregir». No pongas enlaces. Vence en '.ContiActionService::TTL_MINUTES.' minutos.',
        ];
    }

    /** El formulario de una acción, para que la persona lo complete en el chat. */
    public function showForm(string $action, array $data): array
    {
        return ['interaccion' => ['tipo' => 'formulario', 'formulario' => $this->forms->build($this->context, $action, $data)]];
    }

    /**
     * Preguntas con opciones, para que la persona elija en el chat. Acepta
     * las opciones como texto suelto o como {etiqueta, descripcion}.
     */
    public function ask(array $questions): array
    {
        $questions = array_map(function ($question) {
            if (! is_array($question)) {
                return $question;
            }

            $question['opciones'] = array_map(
                fn ($option) => is_string($option) ? ['etiqueta' => $option] : $option,
                is_array($question['opciones'] ?? null) ? $question['opciones'] : [],
            );

            return $question;
        }, array_values($questions));

        $valid = Validator::make(['preguntas' => $questions], [
            'preguntas' => ['required', 'array', 'min:1', 'max:4'],
            'preguntas.*.pregunta' => ['required', 'string', 'max:300'],
            'preguntas.*.encabezado' => ['nullable', 'string', 'max:40'],
            'preguntas.*.multiple' => ['nullable', 'boolean'],
            'preguntas.*.opciones' => ['required', 'array', 'min:2', 'max:6'],
            'preguntas.*.opciones.*.etiqueta' => ['required', 'string', 'max:120'],
            'preguntas.*.opciones.*.descripcion' => ['nullable', 'string', 'max:300'],
        ])->validate();

        return ['interaccion' => ['tipo' => 'preguntas', 'preguntas' => array_map(fn (array $q) => [
            'pregunta' => $q['pregunta'],
            'encabezado' => $q['encabezado'] ?? null,
            'multiple' => (bool) ($q['multiple'] ?? false),
            'opciones' => array_map(fn (array $o) => ['etiqueta' => $o['etiqueta'], 'descripcion' => $o['descripcion'] ?? null], $q['opciones']),
        ], $valid['preguntas'])]];
    }

    /** En qué quedó una acción preparada para esta persona en esta compañía. */
    public function actionStatus(string $uuid): array
    {
        $pending = ContiAction::where('uuid', $uuid)
            ->where('user_id', $this->context->user()->id)
            ->where('company_id', $this->context->company()->id)
            ->first() ?? abort(404, 'No encontré esa acción.');

        return ContiActionService::present($pending);
    }

    private function detail(ContiResource $definition, string $id): array
    {
        $query = $definition->newQuery();

        $model = ctype_digit($id) ? (clone $query)->whereKey((int) $id)->first() : null;

        if ($model === null && $definition->codeColumn !== null) {
            $model = (clone $query)->where($query->getModel()->qualifyColumn($definition->codeColumn), $id)->first();
        }

        if ($model === null) {
            abort(404, "No encontré «{$id}» en {$definition->label}.");
        }

        return [
            'consulta' => $definition->key,
            'nombre' => $definition->label,
            'registro' => $definition->presentDetail($model),
        ];
    }

    private function applySearch(ContiResource $definition, Builder $query, string $term): void
    {
        if ($definition->search instanceof \Closure) {
            ($definition->search)($query, $term);

            return;
        }

        if ($definition->search === []) {
            abort(422, "{$definition->label} no admite búsqueda por texto: usá sus filtros.");
        }

        $query->where(function (Builder $where) use ($definition, $query, $term) {
            foreach ($definition->search as $column) {
                $where->orWhere($query->getModel()->qualifyColumn($column), 'like', "%{$term}%");
            }
        });
    }

    private function describeResource(string $key): array
    {
        $resource = ContiResourceCatalog::find($key) ?? abort(404, "No existe la consulta «{$key}».");

        if ($resource->screens !== []) {
            $this->context->authorize($resource->screens);
        }

        return $resource->describe();
    }

    private function describeReport(string $key): array
    {
        $report = ContiReportCatalog::find($key) ?? abort(404, "No existe el reporte «{$key}».");
        $this->context->authorize($report->screens);

        return $report->describe();
    }

    private function describeAction(string $key): array
    {
        $action = ContiActionCatalog::find($key) ?? abort(404, "No existe la acción «{$key}».");
        $this->context->authorize($action->screen(), 'read_write');

        return [
            'accion' => $action->key(),
            'nombre' => $action->label(),
            'descripcion' => $action->description(),
            'datos' => $action->fields(),
        ];
    }

    /**
     * Los parámetros como texto: el modelo manda números y booleanos de
     * JSON, y los filtros y validaciones esperan lo que llegaría por URL.
     *
     * @return array<string, string>
     */
    private function scalars(array $params): array
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if (! is_string($key) || ! (is_scalar($value) || $value === null)) {
                continue;
            }

            $clean[$key] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'sí' : 'no',
                default => trim((string) $value),
            };
        }

        return $clean;
    }
}
