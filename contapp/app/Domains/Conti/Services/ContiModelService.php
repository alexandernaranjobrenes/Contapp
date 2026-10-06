<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Agent\OpenAiClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Con qué modelo de OpenAI le responde Conti a cada persona (CLAUDE.md
 * secc. 32). Lo elige ella en «Modelo y consumo», entre los de conti.models
 * —los que tienen precio en conti.pricing, para que el consumo salga bien—.
 * Sin elegir, el predeterminado de la instalación (OPENAI_MODEL).
 *
 * La lista que se ofrece es la de conti.models, menos los que la key no puede
 * usar: eso se le pregunta a OpenAI (no consume tokens) y se recuerda un día.
 * Si no se pudo preguntar, se ofrecen todos. Y si el Superusuario le limitó
 * los modelos a la persona (ContiAccessService), solo esos.
 */
class ContiModelService
{
    public function __construct(private readonly OpenAiClient $client) {}

    public function default(): string
    {
        return (string) config('services.openai.model');
    }

    /**
     * Todos los que se pueden ofrecer, con lo que ve la persona. El
     * predeterminado siempre está, aunque no figure en conti.models.
     *
     * @return array<string, array{id: string, nombre: string, descripcion: string, costo: ?string, razona: bool, predeterminado: bool}>
     */
    public function catalog(): array
    {
        $models = config('conti.models', []);
        $default = $this->default();

        if (! isset($models[$default])) {
            $models = [$default => ['name' => $default, 'description' => 'El modelo predeterminado de esta instalación de CONTAPP.']] + $models;
        }

        $catalog = [];
        foreach ($models as $id => $model) {
            $catalog[$id] = [
                'id' => $id,
                'nombre' => $model['name'] ?? $id,
                'descripcion' => $model['description'] ?? '',
                'costo' => $model['cost'] ?? null,
                'razona' => (bool) ($model['reasoning'] ?? false),
                'predeterminado' => $id === $default,
            ];
        }

        return $catalog;
    }

    /**
     * Los que la persona puede elegir: los del catálogo que la key puede usar
     * y, si el Superusuario se los limitó, que estén entre los permitidos.
     *
     * @param  list<string>|null  $allowed  null: todos
     * @return list<array{id: string, nombre: string, descripcion: string, costo: ?string, razona: bool, predeterminado: bool}>
     */
    public function available(?array $allowed = null): array
    {
        $catalog = $allowed === null
            ? $this->catalog()
            : array_filter($this->catalog(), fn (array $m) => in_array($m['id'], $allowed, true));
        $usable = $this->usableIds();

        if ($usable === null) {
            return array_values($catalog);
        }

        // El predeterminado se ofrece igual: es con el que ya responde Conti.
        return array_values(array_filter($catalog, fn (array $m) => $m['predeterminado'] || in_array($m['id'], $usable, true)));
    }

    /**
     * El modelo de esta persona: el que eligió, si todavía se ofrece y lo
     * tiene permitido; si no, el predeterminado, o el primero de los que
     * tiene permitidos.
     *
     * @param  list<string>|null  $allowed  null: todos
     */
    public function forUser(User $user, ?array $allowed = null): string
    {
        $catalog = $this->catalog();
        $permitted = fn (?string $id) => $id !== null && isset($catalog[$id]) && ($allowed === null || in_array($id, $allowed, true));

        if ($permitted($user->conti_model)) {
            return $user->conti_model;
        }

        if ($permitted($this->default())) {
            return $this->default();
        }

        foreach (array_keys($catalog) as $id) {
            if ($permitted($id)) {
                return $id;
            }
        }

        return $this->default();
    }

    public function name(string $model): string
    {
        return $this->catalog()[$model]['nombre'] ?? $model;
    }

    public function reasons(string $model): bool
    {
        return $this->catalog()[$model]['razona'] ?? false;
    }

    /**
     * Los modelos que la key puede usar, recordados un día. Lo que no se pudo
     * averiguar no se recuerda: se vuelve a preguntar la próxima vez.
     *
     * @return list<string>|null
     */
    private function usableIds(): ?array
    {
        // Por key: si se cambia, la lista es otra.
        $key = 'conti:openai-models:'.substr(hash('sha256', (string) config('services.openai.key')), 0, 16);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $ids = $this->client->models();

        if ($ids !== null) {
            Cache::put($key, $ids, now()->addMinutes((int) config('conti.models_cache_minutes', 1440)));
        }

        return $ids;
    }
}
