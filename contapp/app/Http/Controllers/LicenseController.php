<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Licensing\Services\LicenseService;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Protegido por el guard 'propietario' (routes/web.php, prefijo /backoffice):
 * License es global, sin CompanyScope, a propósito — el dueño de CONTAPP
 * necesita ver/emitir licencias de TODAS las compañías, no de una sola.
 *
 * Cada acción queda en la bitácora con el Propietario que la hizo (ver
 * RecordsPropietarioAudit). La clave de la licencia nunca se guarda ahí:
 * auditable_id ya dice cuál licencia es.
 */
class LicenseController extends Controller
{
    use RecordsPropietarioAudit;

    /** Lo que el Propietario puede cambiar al editar (ver update()). */
    private const EDITABLE = ['category_id', 'max_companies', 'max_admins', 'max_users', 'notes'];

    /** Cuántos movimientos trae el historial de la ficha (ver history()). */
    private const HISTORY_LIMIT = 50;

    public function index(): Response
    {
        $licenses = License::withCount('companies')
            ->with(['issuedBy:id,name', 'category:id,name', 'commercialProfile.followUps', 'superuser:id,name,email'])
            ->orderByDesc('created_at')
            ->get()
            // Sin `code`: es la clave con la que el cliente activa la licencia
            // (CLAUDE.md secc. 13), y la pantalla solo usa masked_code. Si
            // viajara igual, quedaría a la vista en los datos de la página;
            // la clave completa se pide aparte, con code().
            ->map(fn (License $license) => [
                ...Arr::except($license->toArray(), ['code']),
                'masked_code' => $license->maskedCode(),
                'display_status' => $license->displayStatus(),
                'next_pending_follow_up' => $license->commercialProfile?->nextPendingFollowUp(),
                'admins_count' => $license->adminsCount(),
                'users_count' => $license->usersCount(),
            ]);

        return Inertia::render('Backoffice/Licenses/Index', [
            'licenses' => $licenses,
            'categories' => LicenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name', 'max_companies', 'max_admins', 'max_users', 'duration_months']),
        ]);
    }

    /**
     * La clave completa de una licencia, a pedido: el botón "Ver código
     * completo" de la ficha. No viaja en los datos de la página (ver index()
     * y CLAUDE.md secc. 13): llega al navegador solo cuando el Propietario la
     * pide, y no-store evita que quede guardada en la caché del navegador.
     *
     * Se registra ANTES de responder: si la bitácora falla, la clave no sale.
     * Nunca queda una clave vista sin su entrada.
     */
    public function code(Request $request, int $license): JsonResponse
    {
        $license = License::findOrFail($license);

        $this->auditPropietario($request, 'license_code_revealed', $license);

        return response()
            ->json(['code' => $license->code])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * El historial de la licencia para su ficha: lo que la bitácora registró
     * sobre ella, del más reciente al más antiguo, hasta HISTORY_LIMIT.
     *
     * La hora va en ISO 8601 con su zona (la base guarda UTC): el navegador
     * la muestra en la hora local de quien la mira. La categoría va por
     * nombre, no por id; si ya no existe, por su número.
     */
    public function history(int $license): JsonResponse
    {
        $license = License::findOrFail($license);

        $entries = AuditLog::with(['propietario:id,name', 'user:id,name'])
            ->where('auditable_type', $license->getMorphClass())
            ->where('auditable_id', $license->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT + 1)
            ->get();

        $hasMore = $entries->count() > self::HISTORY_LIMIT;
        $entries = $entries->take(self::HISTORY_LIMIT);

        $categoryIds = $entries
            ->flatMap(fn (AuditLog $entry) => [$entry->old_values['category_id'] ?? null, $entry->new_values['category_id'] ?? null])
            ->filter()
            ->unique();
        $categoryNames = LicenseCategory::whereIn('id', $categoryIds)->pluck('name', 'id');

        return response()
            ->json([
                'entries' => $entries->map(fn (AuditLog $entry) => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'at' => $entry->created_at?->toIso8601String(),
                    'actor' => $entry->propietario?->name ?? $entry->user?->name,
                    'ip' => $entry->ip_address,
                    'changes' => $this->historyChanges($entry, $categoryNames),
                ])->values(),
                'has_more' => $hasMore,
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Los campos que tocó una acción, con el valor de antes y el de después.
     * Un campo vacío en los dos lados (las notas de una licencia emitida sin
     * notas) no dice nada y no se incluye.
     *
     * @param  Collection<int, string>  $categoryNames
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    private function historyChanges(AuditLog $entry, Collection $categoryNames): array
    {
        $old = $entry->old_values ?? [];
        $new = $entry->new_values ?? [];
        $categoryName = fn ($id) => $id === null ? null : ($categoryNames[$id] ?? "#{$id}");

        return collect(array_unique([...array_keys($new), ...array_keys($old)]))
            ->map(function (string $field) use ($old, $new, $categoryName) {
                $from = $old[$field] ?? null;
                $to = $new[$field] ?? null;

                if ($field === 'category_id') {
                    [$from, $to] = [$categoryName($from), $categoryName($to)];
                }

                return ['field' => $field, 'from' => $from, 'to' => $to];
            })
            ->reject(fn (array $change) => $change['from'] === null && $change['to'] === null)
            ->values()
            ->all();
    }

    public function store(Request $request, LicenseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:license_categories,id'],
            'expires_at' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $category = LicenseCategory::findOrFail($validated['category_id']);

        $license = DB::transaction(function () use ($request, $service, $category, $validated) {
            $license = $service->issue(
                $category,
                new \DateTime($validated['expires_at']),
                $validated['notes'] ?? null,
                $request->user('propietario')->id,
            );

            $this->auditPropietario($request, 'license_issued', $license, null, $this->auditSnapshot(
                $license, ['category_id', 'max_companies', 'max_admins', 'max_users', 'expires_at', 'notes'],
            ));

            return $license;
        });

        // Excepción al código enmascarado (CLAUDE.md secc. 13): recién emitida,
        // el Propietario necesita la clave completa para entregársela al
        // cliente, que la ingresa en /activate. Después solo se ve a pedido,
        // con "Ver código completo" en la ficha (ver code()).
        return back()->with('success', "Licencia emitida: {$license->code}. Entregale esta clave al cliente para que la active.");
    }

    public function update(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:license_categories,id'],
            'max_companies' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_admins' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_users' => ['required', 'integer', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($request, $license, $service, $validated) {
                $before = $license->only(self::EDITABLE);
                $service->update($license, $validated);
                [$old, $new] = $this->changedValues($before, $license->only(self::EDITABLE));

                if ($new) {
                    $this->auditPropietario($request, 'license_updated', $license, $old, $new);
                }
            });
        } catch (InvalidLicenseException $e) {
            return back()->withErrors(['license' => $e->getMessage()]);
        }

        return back()->with('success', "Licencia {$license->maskedCode()} actualizada.");
    }

    public function renew(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'expires_at' => ['required', 'date', 'after:today'],
        ]);

        $license = License::findOrFail($license);

        DB::transaction(function () use ($request, $license, $service, $validated) {
            $before = $this->auditSnapshot($license, ['expires_at', 'status']);
            $service->renew($license, new \DateTime($validated['expires_at']));

            $this->auditPropietario($request, 'license_renewed', $license, $before, $this->auditSnapshot($license, ['expires_at', 'status']));
        });

        return back()->with('success', "Licencia {$license->maskedCode()} renovada.");
    }

    public function revoke(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $this->changeStatus($request, $license, 'license_revoked', fn () => $service->revoke($license));

        return back()->with('success', "Licencia {$license->maskedCode()} revocada.");
    }

    public function suspend(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $this->changeStatus($request, $license, 'license_suspended', fn () => $service->suspend($license));

        return back()->with('success', "Licencia {$license->maskedCode()} suspendida.");
    }

    public function reactivate(Request $request, int $license, LicenseService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $this->changeStatus($request, $license, 'license_reactivated', fn () => $service->reactivate($license));

        return back()->with('success', "Licencia {$license->maskedCode()} reactivada.");
    }

    /**
     * Suspender, reactivar o revocar: el cambio de estado y su entrada en la
     * bitácora, en la misma transacción.
     */
    private function changeStatus(Request $request, License $license, string $action, callable $apply): void
    {
        DB::transaction(function () use ($request, $license, $action, $apply) {
            $before = $license->status;
            $apply();

            $this->auditPropietario($request, $action, $license, ['status' => $before], ['status' => $license->status]);
        });
    }
}
