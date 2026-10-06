<?php

namespace App\Http\Controllers\Api\Conti;

use App\Domains\Conti\Resources\ContiResource;
use App\Domains\Conti\Resources\ContiResourceCatalog;
use App\Domains\Conti\Support\ContiContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/conti/datos/{recurso}: los registros de un conjunto, con
 * búsqueda, filtros, rango de fechas y páginas. Con «id», el detalle de uno.
 *
 * Cada conjunto pide sus pantallas (ContiContext::authorize) y pasa por el
 * CompanyScope: nunca sale nada de otra compañía, ni de una pantalla que la
 * persona no tiene.
 */
class RecordController extends ContiApiController
{
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    /** Los parámetros que no son filtros del conjunto. */
    private const RESERVED = ['buscar', 'desde', 'hasta', 'limite', 'pagina', 'id', 'orden'];

    public function show(Request $request, ContiContext $context, string $resource): JsonResponse
    {
        $definition = ContiResourceCatalog::find($resource)
            ?? abort(404, "No existe el conjunto «{$resource}». Consultá el catálogo para ver los disponibles.");

        if ($definition->screens !== []) {
            $context->authorize($definition->screens);
        }

        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'id' => ['nullable', 'string', 'max:100'],
        ]);

        if (filled($validated['id'] ?? null)) {
            return $this->detail($definition, (string) $validated['id']);
        }

        return $this->list($definition, $request, $validated);
    }

    private function list(ContiResource $definition, Request $request, array $validated): JsonResponse
    {
        $query = $definition->newQuery();
        $params = $request->except(self::RESERVED);

        $unknown = array_diff(array_keys($params), array_keys($definition->filters));

        if ($unknown !== []) {
            abort(422, 'Filtros que este conjunto no tiene: '.implode(', ', $unknown).'. Los que acepta: '
                .(implode(', ', array_keys($definition->filters)) ?: 'ninguno').'.');
        }

        if ($definition->defaultFilter) {
            ($definition->defaultFilter)($query, $params);
        }

        foreach ($params as $name => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                ($definition->filters[$name][1])($query, trim((string) $value));
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

        $rows = $query->forPage($page, $limit)->get()->map(fn ($model) => $definition->present($model))->all();

        return $this->respond([
            'conjunto' => $definition->key,
            'nombre' => $definition->label,
            'total' => $total,
            'pagina' => $page,
            'limite' => $limit,
            'hay_mas' => $page * $limit < $total,
            'registros' => $rows,
        ]);
    }

    private function detail(ContiResource $definition, string $id): JsonResponse
    {
        $query = $definition->newQuery();

        $model = ctype_digit($id)
            ? (clone $query)->whereKey((int) $id)->first()
            : null;

        if ($model === null && $definition->codeColumn !== null) {
            $model = (clone $query)->where($query->getModel()->qualifyColumn($definition->codeColumn), $id)->first();
        }

        if ($model === null) {
            abort(404, "No encontré «{$id}» en {$definition->label}.");
        }

        return $this->respond([
            'conjunto' => $definition->key,
            'nombre' => $definition->label,
            'registro' => $definition->presentDetail($model),
        ]);
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
}
