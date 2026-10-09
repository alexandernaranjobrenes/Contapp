<?php

namespace App\Http\Controllers;

use App\Domains\Core\Services\ActivityLogService;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Bitácora (CLAUDE.md secc. 35): el historial de lo que se
 * hizo en la compañía y quién lo hizo. Superusuario y Administradores
 * (middleware can-manage-company); lo que ve cada uno lo decide
 * ActivityLogService.
 *
 * De a 15: la pantalla llega con las primeras y «Ver más» pide las
 * siguientes (more), para no traer de una vez años de historia.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, ActivityLogService $log): Response
    {
        return Inertia::render('ActivityLog/Index', [
            'log' => $log->page($request->user(), (int) $currentCompany->id()),
            'pageSize' => ActivityLogService::PAGE,
        ]);
    }

    public function more(Request $request, CurrentCompany $currentCompany, ActivityLogService $log): JsonResponse
    {
        $validated = $request->validate(['before' => ['required', 'integer', 'min:1']]);

        return response()->json($log->page($request->user(), (int) $currentCompany->id(), (int) $validated['before']));
    }
}
