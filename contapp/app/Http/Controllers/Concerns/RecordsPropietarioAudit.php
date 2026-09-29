<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Bitácora de las acciones del Propietario (plano del fabricante, CLAUDE.md
 * secc. 11/14): licencias, categorías y el catálogo nacional de IVA. Una
 * acción del Propietario no tiene compañía ni usuario de compañía; quién la
 * hizo queda en propietario_id, junto con la IP.
 *
 * Cada acción que escribe se registra dentro de la misma transacción que el
 * cambio: si la bitácora falla, el cambio tampoco queda. Un historial que
 * puede tener huecos no sirve para auditar.
 */
trait RecordsPropietarioAudit
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function auditPropietario(
        Request $request,
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        AuditLog::create([
            'propietario_id' => $request->user('propietario')->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Solo los campos que cambiaron, con su valor de antes y el de ahora: un
     * guardado sin cambios no deja entrada. Las fechas van como Y-m-d, igual
     * que en pantalla. La comparación es flexible (!=) a propósito: "13" y
     * "13.00", o 1 y true, son el mismo dato llegado por caminos distintos.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function changedValues(array $before, array $after): array
    {
        $normalize = fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        $before = array_map($normalize, $before);
        $after = array_map($normalize, $after);

        $changed = array_keys(array_filter(
            $after,
            fn ($value, $key) => $value != ($before[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
        ];
    }

    /**
     * Foto de los campos de un registro con las fechas como Y-m-d: lo que
     * queda en la bitácora al crear o eliminar.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function auditSnapshot(Model $model, array $keys): array
    {
        return array_map(
            fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            $model->only($keys),
        );
    }
}
