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
 * (rango de fechas, documento, usuario y el número o nombre del registro)
 * viajan en la dirección y «Ver más» los repite.
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
            // Las personas que aparecen en lo que ve, y «Sistema».
            'people' => $log->people($request->user(), $companyId),
        ]);
    }

    public function more(Request $request, CurrentCompany $currentCompany, ActivityLogService $log): JsonResponse
    {
        $validated = $request->validate(['before' => ['required', 'integer', 'min:1']]);
        $companyId = (int) $currentCompany->id();

        return response()->json($log->page($request->user(), $companyId, (int) $validated['before'], $this->filters($request, $log, $companyId)));
    }

    /** @return array{from: ?string, to: ?string, document: ?string, user: ?string, search: ?string} */
    private function filters(Request $request, ActivityLogService $log, int $companyId): array
    {
        // La persona: su id, o «system». No hace falta que esté en la lista de
        // people(): la consulta ya es solo de la compañía y de lo que se ve.
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'document' => ['nullable', 'string', Rule::in($log->documentKeys($request->user(), $companyId))],
            'user' => ['nullable', 'string', 'regex:/^('.ActivityLogService::SYSTEM.'|[1-9]\d{0,18})$/'],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'document.in' => 'No tenés acceso a ese documento.',
            'user.regex' => 'Ese usuario no es válido.',
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
            'user' => $validated['user'] ?? null,
            'search' => $search !== '' ? $search : null,
        ];
    }
}
