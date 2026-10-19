<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Services\Hacienda\HaciendaPublicData;
use App\Domains\Billing\Support\CabysCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultas al API público de Hacienda desde las pantallas: el buscador de
 * CAByS (ficha del artículo, línea de la factura) y lo que Hacienda tiene
 * inscrito de una cédula (actividades económicas de la compañía o del socio).
 *
 * Son datos públicos, no de la compañía activa: cualquier cuenta puede
 * consultarlos. `available: false` es «Hacienda no contestó», que la pantalla
 * dice tal cual en vez de mostrar una lista vacía como si no hubiera nada.
 */
class HaciendaLookupController extends Controller
{
    public function __construct(private readonly HaciendaPublicData $hacienda) {}

    /**
     * La lista de CAByS: el catálogo oficial completo (CabysCatalog), de a
     * páginas, filtrado por `q` (código o palabras de la descripción). Con
     * `code`, ese código: del catálogo, o de Hacienda si es más nuevo.
     */
    public function cabys(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'regex:/^\d{13}$/'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        if (filled($validated['code'] ?? null)) {
            $entry = $this->hacienda->cabys($validated['code']);

            return response()->json([
                'available' => $entry !== null,
                'exists' => $entry['exists'] ?? null,
                'entry' => ($entry['exists'] ?? false) ? collect($entry)->except('exists') : null,
            ]);
        }

        $offset = (int) ($validated['offset'] ?? 0);
        $page = CabysCatalog::search((string) ($validated['q'] ?? ''), $offset, 100);

        return response()->json([
            'available' => true,
            'total' => $page['total'],
            'offset' => $offset,
            'results' => $page['results'],
        ]);
    }

    public function taxpayer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identification' => ['required', 'string', 'max:25'],
        ]);

        $taxpayer = $this->hacienda->taxpayer($validated['identification']);

        return response()->json([
            'available' => $taxpayer !== null,
            'taxpayer' => $taxpayer,
        ]);
    }
}
