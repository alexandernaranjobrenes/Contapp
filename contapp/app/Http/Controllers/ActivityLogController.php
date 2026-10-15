<?php

namespace App\Http\Controllers;

use App\Domains\Core\Services\ActivityLogService;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Bitácora (CLAUDE.md secc. 35): el historial de lo que se
 * hizo en la compañía y quién lo hizo. Superusuario y Administradores
 * (middleware can-manage-company); lo que ve cada uno lo decide
 * ActivityLogService.
 *
 * De a 15: la pantalla llega con las primeras y «Ver más» pide las
 * siguientes (more), para no traer de una vez años de historia. Los filtros
 * (rango de fechas, documento y su número o nombre) viajan en la dirección y
 * «Ver más» los repite.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, ActivityLogService $log): Response
    {
        $companyId = (int) $currentCompany->id();
        $filters = $this->filters($request, $log, $companyId);

        return Inertia::render('ActivityLog/Index', [
            'log' => $log->page($request->user(), $companyId, null, $filters),
            'pageSize' => ActivityLogService::PAGE,
            'filters' => $filters,
            // Solo lo que la persona puede ver: un Administrador sin Planillas
            // no tiene «Empleados» para elegir.
            'documents' => $log->documents($request->user(), $companyId),
        ]);
    }

    public function more(Request $request, CurrentCompany $currentCompany, ActivityLogService $log): JsonResponse
    {
        $validated = $request->validate(['before' => ['required', 'integer', 'min:1']]);
        $companyId = (int) $currentCompany->id();

        return response()->json($log->page($request->user(), $companyId, (int) $validated['before'], $this->filters($request, $log, $companyId)));
    }

    /** @return array{from: ?string, to: ?string, document: ?string, search: ?string} */
    private function filters(Request $request, ActivityLogService $log, int $companyId): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'document' => ['nullable', 'string', Rule::in($log->documentKeys($request->user(), $companyId))],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'document.in' => 'No tenés acceso a ese documento.',
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        // Al revés también se entiende.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $search = trim((string) ($validated['search'] ?? ''));

        return [
            'from' => $from,
            'to' => $to,
            'document' => $validated['document'] ?? null,
            'search' => $search !== '' ? $search : null,
        ];
    }
}
