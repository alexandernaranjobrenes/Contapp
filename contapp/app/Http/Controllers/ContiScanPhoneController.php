<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Services\ContiScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La página que abre el QR en el teléfono (CLAUDE.md secc. 32): solo sirve
 * para tomar las fotos de ese escaneo. Sin sesión: la vale el pase del QR, de
 * un solo uso y que vence en minutos (ContiScanService). No muestra ningún
 * dato de la compañía más que su nombre y lo que se va a registrar.
 *
 * Es una página aparte, sin Inertia ni Vite: en desarrollo el teléfono no
 * llega al servidor de Vite («localhost» es el propio teléfono).
 */
class ContiScanPhoneController extends Controller
{
    public function show(string $token, ContiScanService $scans): View
    {
        return view('conti.scan', [
            'scan' => $scans->forToken($token),
            'token' => $token,
            'maxPhotos' => (int) config('conti.scanner.max_photos', 3),
        ]);
    }

    public function upload(Request $request, string $token, ContiScanService $scans): JsonResponse
    {
        $validated = $request->validate(ContiScanController::photoRules(), ContiScanController::photoMessages());
        @set_time_limit(180);

        $scans->readFromPhone($token, ContiScanController::images($validated['fotos']));

        return response()->json(['message' => 'Listo: revisalo en la computadora.']);
    }
}
