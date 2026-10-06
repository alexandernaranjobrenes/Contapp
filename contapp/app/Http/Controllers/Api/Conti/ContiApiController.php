<?php

namespace App\Http\Controllers\Api\Conti;

use App\Domains\Conti\Support\ContiRedactor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Base de la API de Conti: todo sale por ContiRedactor y en UTF-8 tal cual
 * («Catálogo», no «Catálogo»), que es más corto y es lo que lee el
 * agente.
 */
abstract class ContiApiController extends Controller
{
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(ContiRedactor::clean($data), $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
