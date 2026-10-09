<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Services\BarcodeScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;

/**
 * Escanear el código de barras de un artículo con el teléfono, desde la
 * computadora (BarcodeScanService, CLAUDE.md secc. 33): el QR y la pregunta
 * de si ya llegó. Lo pide el formulario del artículo, con Lectura y
 * escritura en Artículos.
 */
class ItemBarcodeScanController extends Controller
{
    public function start(Request $request, CurrentCompany $currentCompany, BarcodeScanService $scans): JsonResponse
    {
        $validated = $request->validate(['para' => ['nullable', 'string', 'max:255']]);

        return response()->json(
            $scans->start($request->user(), (int) $currentCompany->id(), $validated['para'] ?? null),
            201,
        );
    }

    public function status(Request $request, CurrentCompany $currentCompany, BarcodeScanService $scans, string $id): JsonResponse
    {
        // Pregunta cada pocos segundos: no hay nada que guardar en la sesión.
        $request->session()->setHandler(new NullSessionHandler);

        return response()->json($scans->status($request->user(), (int) $currentCompany->id(), $id))->header('Cache-Control', 'no-store');
    }
}
