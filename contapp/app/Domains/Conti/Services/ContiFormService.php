<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Actions\ContiAction;
use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Company;

/**
 * Los formularios que Conti le muestra a la persona en el chat para
 * registrar o editar (CLAUDE.md secc. 32): los campos de cada acción
 * (ContiAction::form), con lo que ya se sabe precargado.
 *
 * Además de lo que dijo la persona, el formulario llega con sugerencias de
 * CONTAPP según cómo se viene trabajando en la compañía (ContiAction::
 * suggest): el código que sigue, la cuenta de control que usan los demás…
 * Van marcadas, con su motivo, y se recalculan al cambiar un campo clave.
 *
 * El formulario no guarda nada: al enviarlo se prepara la acción
 * (ContiActionService::prepare, con las mismas validaciones de siempre) y
 * CONTAPP abre la ventana para confirmarla.
 */
class ContiFormService
{
    /**
     * De qué consulta salen las sugerencias de un campo «buscar», qué se toma
     * como valor y qué se muestra al lado.
     */
    public const SOURCES = [
        'cuentas' => ['valor' => 'codigo', 'muestra' => ['nombre', 'clase']],
        'socios' => ['valor' => 'codigo', 'muestra' => ['nombre', 'tipo']],
        'articulos' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'almacenes' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'centros-costo' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'normas-reparto' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'tipos-documento' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'empleados' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'conceptos-planilla' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'periodos-planilla' => ['valor' => 'id', 'muestra' => ['nombre', 'estado']],
        'listas-precios' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'indicadores-impuesto' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'categorias-socios' => ['valor' => 'codigo', 'muestra' => ['nombre']],
        'partidas-abiertas' => ['valor' => 'id', 'muestra' => ['documento', 'socio', 'saldo']],
    ];

    /**
     * El formulario de una acción, con lo que ya se sabe. Corta con 403 si la
     * persona no puede registrar eso (o la licencia está vencida), igual que
     * al prepararlo.
     *
     * @return array{accion: string, titulo: string, campos: list<array>, valores: array, sugeridos: array}
     */
    public function build(ContiContext $context, string $key, array $prefill = []): array
    {
        $action = $this->allowed($context, $key);
        $company = $context->company();
        $fields = $action->form($company);
        $values = $this->only($fields, $action->formValues($this->only($fields, $prefill), $company));
        $suggested = $this->suggestionsFor($action, $company, $fields, $values);

        foreach ($suggested as $name => $suggestion) {
            $values = [...$values, ...$this->only($fields, [$name => $suggestion['valor']])];
        }

        return [
            'accion' => $action->key(),
            'titulo' => $action->label(),
            'campos' => $fields,
            'valores' => $this->withDefaults($fields, $values),
            'sugeridos' => $suggested,
        ];
    }

    /**
     * Las sugerencias para lo que todavía está vacío, con lo que ya tiene el
     * formulario: al cambiar un campo clave (la clase de una cuenta, el tipo
     * de un socio), cambian.
     *
     * @return array<string, array{valor: mixed, motivo: string}>
     */
    public function suggestions(ContiContext $context, string $key, array $values): array
    {
        $action = $this->allowed($context, $key);
        $fields = $action->form($context->company());

        return $this->suggestionsFor($action, $context->company(), $fields, $this->only($fields, $values));
    }

    /** La acción, si la persona puede registrarla (como al prepararla). */
    private function allowed(ContiContext $context, string $key): ContiAction
    {
        $action = $this->action($key);
        $context->authorize($action->screen(), 'read_write');

        if ($context->inGrace()) {
            abort(403, ContiActionService::GRACE_MESSAGE);
        }

        return $action;
    }

    /**
     * Solo para campos del formulario que estén vacíos, con el valor como lo
     * muestra el formulario (un sí/no, booleano).
     */
    private function suggestionsFor(ContiAction $action, Company $company, array $fields, array $values): array
    {
        $byName = array_column($fields, null, 'campo');
        $out = [];

        foreach ($action->suggest($values, $company) as $name => $suggestion) {
            $current = $values[$name] ?? '';

            if ($suggestion === null || ! isset($byName[$name]) || ($current !== '' && $current !== null)) {
                continue;
            }

            $shown = $this->only([$byName[$name]], [$name => $suggestion['valor']]);

            if (array_key_exists($name, $shown)) {
                $out[$name] = ['valor' => $shown[$name], 'motivo' => $suggestion['motivo']];
            }
        }

        return $out;
    }

    /**
     * Lo que mandó el formulario, como lo espera la acción: sin campos vacíos
     * (así rigen los valores por defecto de la acción y, al editar, lo que
     * queda vacío no cambia), los sí/no como «sí» o «no» y sin renglones
     * vacíos.
     */
    public function submission(string $key, array $data, Company $company): array
    {
        $out = [];

        foreach ($this->action($key)->form($company) as $field) {
            $value = $data[$field['campo']] ?? null;

            if ($field['tipo'] === 'lineas') {
                $rows = [];

                foreach (is_array($value) ? $value : [] as $row) {
                    $clean = is_array($row) ? $this->scalars($field['columnas'], $row) : [];

                    if ($clean !== []) {
                        $rows[] = $clean;
                    }
                }

                if ($rows !== []) {
                    $out[$field['campo']] = $rows;
                }

                continue;
            }

            $scalar = $this->scalar($field, $value);

            if ($scalar !== null) {
                $out[$field['campo']] = $scalar;
            }
        }

        return $out;
    }

    private function action(string $key): ContiAction
    {
        return ContiActionCatalog::find($key)
            ?? abort(404, "No existe la acción «{$key}». Las disponibles están en la lista de tus instrucciones.");
    }

    /** Solo los campos del formulario, como los mostraría: los sí/no como booleanos. */
    private function only(array $fields, array $values): array
    {
        $out = [];

        foreach ($fields as $field) {
            $name = $field['campo'];

            if (! array_key_exists($name, $values) || $values[$name] === null) {
                continue;
            }

            if ($field['tipo'] === 'lineas') {
                $out[$name] = array_values(array_filter(array_map(
                    fn ($row) => is_array($row) ? $this->only($field['columnas'], $row) : null,
                    is_array($values[$name]) ? $values[$name] : [],
                )));

                continue;
            }

            $value = $values[$name];

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $out[$name] = match ($field['tipo']) {
                'si_no' => is_bool($value) ? $value : ContiFilters::truthy((string) $value),
                'opciones' => $this->option($field, (string) $value),
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * Una opción como la tiene la lista: el modelo manda lo que ve la persona
     * («cliente», «Gastos») y la lista va por clave («client», «expense»).
     */
    private function option(array $field, string $value): string
    {
        $labels = array_column($field['opciones'] ?? [], 'etiqueta', 'valor');
        $key = $value === '' ? '' : ContiFilters::keyFor($value, $labels);

        return array_key_exists($key, $labels) ? $key : $value;
    }

    /** Lo que falta, con su valor por defecto; las líneas, con los renglones mínimos. */
    private function withDefaults(array $fields, array $values): array
    {
        foreach ($fields as $field) {
            $name = $field['campo'];

            if ($field['tipo'] === 'lineas') {
                $rows = array_map(fn (array $row) => $this->withDefaults($field['columnas'], $row), $values[$name] ?? []);

                while (count($rows) < (int) ($field['minimo'] ?? 1)) {
                    $rows[] = $this->withDefaults($field['columnas'], []);
                }

                $values[$name] = $rows;

                continue;
            }

            if (array_key_exists($name, $values)) {
                continue;
            }

            $default = $field['defecto'] ?? null;

            $values[$name] = match (true) {
                $default === 'hoy' => now(config('conti.timezone'))->format('Y-m-d'),
                $field['tipo'] === 'si_no' => (bool) $default,
                $default !== null => (string) $default,
                default => '',
            };
        }

        return $values;
    }

    /** Un renglón de líneas, sin sus vacíos. */
    private function scalars(array $columns, array $row): array
    {
        $out = [];

        foreach ($columns as $column) {
            $scalar = $this->scalar($column, $row[$column['campo']] ?? null);

            // Un sí/no solo no hace un renglón.
            if ($scalar !== null) {
                $out[$column['campo']] = $scalar;
            }
        }

        $meaningful = array_filter($columns, fn (array $c) => $c['tipo'] !== 'si_no' && isset($out[$c['campo']]));

        return $meaningful === [] ? [] : $out;
    }

    private function scalar(array $field, mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        if ($field['tipo'] === 'si_no') {
            if ($value === '') {
                return null;
            }

            return (is_bool($value) ? $value : ContiFilters::truthy((string) $value)) ? 'sí' : 'no';
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 5000);
    }
}
