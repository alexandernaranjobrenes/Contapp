<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Services\ContiScanService;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\NullSessionHandler;

/**
 * Escanear un documento, desde la aplicación (ContiScanModal.vue,
 * CLAUDE.md secc. 32), con la sesión de la persona:
 *
 * - actions: lo que puede registrar desde un documento;
 * - start: el QR para escanear con el teléfono, desde la computadora;
 * - status: en qué va ese escaneo (la computadora pregunta cada pocos
 *   segundos) y, cuando está listo, el formulario;
 * - read: las fotos tomadas en el mismo teléfono, leídas ahí mismo.
 *
 * Las fotos no se guardan (ContiScanService).
 */
class ContiScanController extends Controller
{
    public function actions(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context, ContiScanService $scans): JsonResponse
    {
        $this->context($request, $currentCompany, $usage, $context);

        return response()->json(['acciones' => $scans->actionsFor($context)]);
    }

    public function start(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context, ContiScanService $scans): JsonResponse
    {
        $validated = $request->validate([
            'accion' => ['required', 'string', 'max:60'],
            'sesion' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $this->context($request, $currentCompany, $usage, $context);

        return response()->json($scans->start($context, $validated['accion'], $validated['sesion'] ?? null), 201);
    }

    public function status(Request $request, CurrentCompany $currentCompany, ContiScanService $scans, string $id): JsonResponse
    {
        // Pregunta cada pocos segundos: no hay nada que guardar en la sesión.
        $request->session()->setHandler(new NullSessionHandler);

        return response()->json($scans->status($request->user(), (int) $currentCompany->id(), $id))->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context, ContiScanService $scans): JsonResponse
    {
        $request->session()->setHandler(new NullSessionHandler);
        $validated = $request->validate([
            'accion' => ['required', 'string', 'max:60'],
            'sesion' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            ...self::photoRules(),
        ], self::photoMessages());

        $this->context($request, $currentCompany, $usage, $context);
        @set_time_limit(180);

        return response()->json([
            'formulario' => $scans->read($context, $validated['accion'], self::images($validated['fotos']), $validated['sesion'] ?? null),
        ]);
    }

    /** Las fotos: de 1 a conti.scanner.max_photos imágenes, livianas. */
    public static function photoRules(): array
    {
        return [
            'fotos' => ['required', 'array', 'min:1', 'max:'.(int) config('conti.scanner.max_photos', 3)],
            'fotos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(int) config('conti.scanner.max_kb', 6144)],
        ];
    }

    public static function photoMessages(): array
    {
        return [
            'fotos.required' => 'Tomá al menos una foto del documento.',
            'fotos.max' => 'Como mucho :max fotos por documento.',
            'fotos.*.mimetypes' => 'Solo fotos (JPG, PNG o WEBP).',
            'fotos.*.max' => 'Una foto es demasiado pesada.',
        ];
    }

    /**
     * Las fotos, en memoria, para mandárselas a OpenAI. No se guardan en
     * ningún lado: el archivo temporal de PHP se borra al terminar el pedido.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{data: string, mime: string}>
     */
    public static function images(array $files): array
    {
        return array_map(fn (UploadedFile $file) => ['data' => $file->get(), 'mime' => $file->getMimeType() ?: 'image/jpeg'], $files);
    }

    /** La persona, en su compañía, si tiene Conti. */
    private function context(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context): void
    {
        $companyId = $currentCompany->id() ?? abort(409, 'Elegí una compañía para usar Conti.');
        $company = Company::with('license')->findOrFail($companyId);
        /** @var User $user */
        $user = $request->user();

        abort_unless($usage->enabledFor($company, $user), 403, 'No tenés acceso a Conti en esta compañía.');

        $context->set($user, $company, $currentCompany->isInGracePeriod());
    }
}
