<?php

namespace App\Http\Controllers;

use App\Domains\Inventory\Services\BarcodeScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La página que abre en el teléfono el QR del código de barras (CLAUDE.md
 * secc. 33): solo sirve para leer un código y mandarlo a ese escaneo. Sin
 * sesión: la vale el pase del QR, de un solo uso y que vence en minutos
 * (BarcodeScanService). No muestra más que el nombre de la compañía y del
 * artículo.
 *
 * Como la de Conti, es una página aparte, sin Inertia ni Vite: en desarrollo
 * el teléfono no llega al servidor de Vite. El lector (ZXing) va como
 * archivo en public/vendor/zxing.
 */
class ItemBarcodePhoneController extends Controller
{
    public function show(string $token, BarcodeScanService $scans): View
    {
        return view('inventory.barcode-scan', [
            'scan' => $scans->forToken($token),
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, BarcodeScanService $scans): JsonResponse
    {
        $validated = $request->validate([
            // Lo mismo que admite el artículo (ItemController::rules).
            'codigo' => ['required', 'string', 'max:255', 'regex:/^[\x20-\x7E]+$/'],
        ], [
            'codigo.required' => 'No llegó ningún código. Escaneá de nuevo.',
            'codigo.regex' => 'Ese código no se puede usar: tiene caracteres que no van en un código de barras.',
        ]);

        $scans->deliver($token, trim($validated['codigo']));

        return response()->json(['message' => 'Listo: el código ya está en la computadora.']);
    }
}
