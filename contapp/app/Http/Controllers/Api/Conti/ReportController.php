<?php

namespace App\Http\Controllers\Api\Conti;

use App\Domains\Conti\Reports\ContiReportCatalog;
use App\Domains\Conti\Support\ContiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/conti/reportes/{reporte}: corre un reporte con los parámetros que
 * mande el agente (los de ContiReportCatalog) y devuelve su resultado.
 */
class ReportController extends ContiApiController
{
    public function show(Request $request, ContiContext $context, string $report): JsonResponse
    {
        $definition = ContiReportCatalog::find($report)
            ?? abort(404, "No existe el reporte «{$report}». Consultá el catálogo para ver los disponibles.");

        $context->authorize($definition->screens);

        $params = array_filter($request->query(), fn ($value) => is_scalar($value));
        $unknown = array_diff(array_keys($params), array_keys($definition->params));

        if ($unknown !== []) {
            abort(422, 'Parámetros que este reporte no tiene: '.implode(', ', $unknown).'. Los que acepta: '
                .(implode(', ', array_keys($definition->params)) ?: 'ninguno').'.');
        }

        return $this->respond([
            'reporte' => $definition->key,
            'nombre' => $definition->label,
            'parametros' => $params,
            'resultado' => ($definition->run)($params),
        ]);
    }
}
