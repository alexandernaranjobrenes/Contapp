<?php

namespace App\Http\Controllers\Site;

use App\Domains\Pricing\Services\LicenseRequestService;
use App\Domains\Pricing\Support\PricingMoney;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Recibe una solicitud de licencia desde /precios (CLAUDE.md secc. 42).
 * Contesta JSON: el configurador la manda sin salir de la página.
 *
 * Contra los robots, tres cosas: un campo trampa que una persona no ve
 * (website), un tiempo mínimo desde que se abrió la página (started, cifrado
 * por el servidor) y un límite por IP (license-requests, AppServiceProvider).
 * El precio lo calcula el servidor: el que mande el navegador no cuenta.
 */
class LicenseRequestController extends Controller
{
    /** Segundos mínimos entre abrir la página y enviar: menos es un robot. */
    private const MIN_SECONDS = 3;

    /** Una página abierta más que esto tiene precios que pueden haber cambiado. */
    private const MAX_HOURS = 12;

    public function store(Request $request, LicenseRequestService $requests): JsonResponse
    {
        // Al robot que llenó el campo trampa se le contesta como si nada.
        if (filled($request->input('website'))) {
            return response()->json(['reference' => null], 201);
        }

        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:120'],
            'company_name' => ['required', 'string', 'max:160'],
            'identification' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z\- ]+$/'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\- ]+$/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'package' => ['nullable', 'string', 'max:100'],
            'cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'currency' => ['required', Rule::in(['CRC', 'USD'])],
            'selection' => ['required', 'array', 'max:100'],
            'selection.*' => ['integer', 'min:0', 'max:9999'],
            'started' => ['required', 'string'],
        ], [
            'consent.accepted' => 'Para enviar la solicitud, aceptá que usemos estos datos para responderte.',
            'identification.regex' => 'La cédula lleva solo números y guiones.',
            'phone.regex' => 'El teléfono lleva solo números, espacios, guiones, paréntesis y «+».',
            'selection.required' => 'Elegí al menos una función para tu plan.',
        ], [
            'contact_name' => 'nombre',
            'company_name' => 'compañía',
            'identification' => 'cédula',
            'email' => 'correo',
            'phone' => 'teléfono',
            'message' => 'mensaje',
        ]);

        $this->ensureHuman($validated['started']);

        try {
            $licenseRequest = $requests->create($validated, $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['selection' => $e->getMessage()]);
        }

        return response()->json([
            'reference' => $licenseRequest->reference(),
            'email' => $licenseRequest->email,
            'total' => PricingMoney::format((int) round((float) $licenseRequest->total * 100), $licenseRequest->currency),
            'cycle' => PricingMoney::cycle($licenseRequest->billing_cycle),
        ], 201);
    }

    /** @throws ValidationException */
    private function ensureHuman(string $started): void
    {
        try {
            $openedAt = (int) Crypt::decryptString($started);
        } catch (DecryptException) {
            $openedAt = 0;
        }

        $elapsed = now()->timestamp - $openedAt;

        if ($openedAt === 0 || $elapsed > self::MAX_HOURS * 3600) {
            throw ValidationException::withMessages(['form' => 'La página estuvo abierta mucho tiempo y los precios pueden haber cambiado. Recargala y volvé a enviar la solicitud.']);
        }

        if ($elapsed < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['form' => 'Esperá unos segundos y volvé a enviarla.']);
        }
    }
}
