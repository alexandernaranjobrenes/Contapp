<?php

namespace App\Http\Controllers\Api\Conti;

use App\Domains\Conti\Models\ContiAction;
use App\Domains\Conti\Services\ContiActionService;
use App\Domains\Conti\Support\ContiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que Conti prepara para guardar (ContiActionService).
 *
 * - POST /api/conti/acciones: {accion, datos}. Valida, lo prueba en seco y
 *   lo deja pendiente. Devuelve el enlace en el que la persona lo revisa y
 *   lo confirma: nada se guarda hasta entonces.
 * - GET /api/conti/acciones/{id}: en qué quedó (pendiente, guardado,
 *   descartado, no se pudo guardar, vencido).
 */
class ActionController extends ContiApiController
{
    public function store(Request $request, ContiContext $context, ContiActionService $service): JsonResponse
    {
        $validated = $request->validate([
            'accion' => ['required', 'string', 'max:60'],
            'datos' => ['present', 'array'],
        ]);

        $pending = $service->prepare($context, $validated['accion'], $validated['datos']);

        return $this->respond([
            ...ContiActionService::present($pending),
            'mensaje' => 'Listo para confirmar. Todavía no se guardó nada: la persona tiene que abrir el enlace, revisar y tocar «Confirmar». '
                .'El enlace vence en '.ContiActionService::TTL_MINUTES.' minutos.',
        ], 201);
    }

    public function show(ContiContext $context, string $uuid): JsonResponse
    {
        $pending = ContiAction::where('uuid', $uuid)
            ->where('user_id', $context->user()->id)
            ->where('company_id', $context->company()->id)
            ->first() ?? abort(404, 'No encontré esa acción.');

        return $this->respond(ContiActionService::present($pending));
    }
}
