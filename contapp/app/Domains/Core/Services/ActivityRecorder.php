<?php

namespace App\Domains\Core\Services;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Support\AuditCatalog;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\ScreenCatalog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Anota en la Bitácora todo lo que cambia en una compañía (CLAUDE.md secc.
 * 35), sin que cada pantalla tenga que acordarse.
 *
 * - Escucha cada alta, cambio y baja de Eloquent de lo que está en
 *   AuditCatalog::MODELS, con lo de antes y lo de después.
 * - Lo de un mismo pedido queda en UNA fila: «Creó el asiento AS-5», con sus
 *   20 líneas adentro, y no 21 filas. Se escribe al terminar el pedido.
 * - Solo lo que se confirmó: cada cambio se suma después del commit de su
 *   transacción (DB::afterCommit). Lo que se deshizo no aparece.
 * - Lo hace una persona con sesión en la compañía, o un proceso automático
 *   (asSystem: los registros programados). El backoffice tiene su propia
 *   bitácora, y las migraciones y los seeders no se anotan.
 * - Inicios y cierres de sesión, en cada compañía en la que la persona está.
 *
 * Si anotar falla, se reporta y sigue: la Bitácora nunca frena el trabajo.
 */
class ActivityRecorder
{
    /** Los registros que se guardan con detalle en una fila; del resto, la cuenta. */
    private const MAX_RECORDS = 150;

    /** @var array<string, array{company_id: int, user_id: ?int, route: ?string, records: array<string, array>}> */
    private array $batches = [];

    private bool $system = false;

    public function record(Model $model, string $event): void
    {
        if (! AuditCatalog::audits($model)) {
            return;
        }

        try {
            $userId = Auth::guard('web')->id();
            $route = request()->route()?->getName();

            // Backoffice, seeders, migraciones: no son de la Bitácora de la compañía.
            if (($userId === null && ! $this->system) || str_starts_with((string) $route, 'backoffice.')) {
                return;
            }

            $companyId = $model->getAttribute('company_id') ?? app(CurrentCompany::class)->id();

            if (! $companyId) {
                return;
            }

            // Lo que cambió, ahora: después del evento Eloquent ya no lo sabe.
            $fields = AuditCatalog::fields($model, $event);

            if ($event === 'updated' && $fields === []) {
                return;
            }

            $entry = ['t' => $model::class, 'id' => $model->getKey(), 'a' => $event, 's' => AuditCatalog::subject($model), 'f' => $fields];
            // Una fila por compañía, persona y acción (la ruta).
            $key = $companyId.'|'.($userId ?? 'sistema').'|'.$route;

            DB::afterCommit(function () use ($key, $companyId, $userId, $route, $entry) {
                $this->batches[$key] ??= ['company_id' => (int) $companyId, 'user_id' => $userId, 'route' => $route, 'records' => []];
                $this->merge($this->batches[$key]['records'], $entry);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Escribe lo juntado: una fila por compañía y persona. */
    public function flush(): void
    {
        $batches = $this->batches;
        $this->batches = [];

        foreach ($batches as $batch) {
            $records = array_values($batch['records']);

            if ($records === []) {
                continue;
            }

            try {
                // Encabeza el documento, no su línea: el primer registro que no es línea.
                $primary = collect($records)->first(fn (array $r) => ! AuditCatalog::isLine($r['t'])) ?? $records[0];

                $omitted = [];
                foreach (array_slice($records, self::MAX_RECORDS) as $r) {
                    $omitted[$r['t']][$r['a']] = ($omitted[$r['t']][$r['a']] ?? 0) + 1;
                }

                AuditLog::create([
                    'company_id' => $batch['company_id'],
                    'user_id' => $batch['user_id'],
                    'action' => AuditLog::ACTIVITY,
                    'route' => $batch['route'],
                    'screen' => ScreenCatalog::screenForRoute($batch['route']) ?? AuditCatalog::screen($primary['t']),
                    'auditable_type' => $primary['t'],
                    'auditable_id' => (int) ($primary['id'] ?? 0),
                    'subject' => $primary['s'],
                    'changes' => ['records' => array_slice($records, 0, self::MAX_RECORDS), 'omitted' => $omitted],
                    'ip_address' => $batch['user_id'] !== null ? request()->ip() : null,
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Un proceso automático (los registros programados del día): lo que haga
     * queda en la Bitácora a nombre de «Sistema».
     */
    public function asSystem(Closure $callback): mixed
    {
        $this->system = true;

        try {
            return $callback();
        } finally {
            $this->system = false;
            $this->flush();
        }
    }

    /** Inició o cerró sesión: en cada compañía en la que la persona está activa. */
    public function session(string $type, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        try {
            $companyIds = $user->companies()->wherePivot('status', 'active')->limit(50)->pluck('companies.id');

            foreach ($companyIds as $companyId) {
                AuditLog::create([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'action' => "auth.{$type}",
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'ip_address' => request()->ip(),
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * El mismo registro varias veces en un pedido es un solo cambio: creado y
     * después editado es «creado» con lo último; editado dos veces, lo
     * primero contra lo último; creado y eliminado, nada.
     *
     * @param  array<string, array>  $records
     */
    private function merge(array &$records, array $entry): void
    {
        $key = $entry['t'].'#'.$entry['id'];
        $previous = $records[$key] ?? null;

        if ($previous === null) {
            $records[$key] = $entry;

            return;
        }

        if ($previous['a'] === 'created' && $entry['a'] === 'deleted') {
            unset($records[$key]);

            return;
        }

        if ($entry['a'] === 'updated' && in_array($previous['a'], ['created', 'updated'], true)) {
            foreach ($entry['f'] as $field => [$old, $new]) {
                $records[$key]['f'][$field] = [$previous['f'][$field][0] ?? $old, $new];
            }
            $records[$key]['s'] = $entry['s'];

            return;
        }

        $records[$key] = $entry;
    }
}
