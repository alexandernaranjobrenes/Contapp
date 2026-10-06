<?php

namespace App\Http\Controllers\Api\Conti;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Conti\Actions\ContiAction;
use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Reports\ContiReport;
use App\Domains\Conti\Reports\ContiReportCatalog;
use App\Domains\Conti\Resources\ContiResource;
use App\Domains\Conti\Resources\ContiResourceCatalog;
use App\Domains\Conti\Support\ContiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/conti/contexto: con quién habla Conti y qué puede hacer.
 *
 * Sin parámetros, lo corto: la persona, su rol, la compañía, la licencia, la
 * fecha, sus permisos y la lista de lo que puede consultar, los reportes que
 * puede pedir y lo que puede guardar —solo lo que sus permisos le dan—.
 *
 * Con ver=consulta|reporte|accion y clave=…, el detalle de uno: sus
 * filtros, sus parámetros o sus campos. Así cada mensaje no carga el
 * catálogo entero.
 */
class ContextController extends ContiApiController
{
    public function show(Request $request, ContiContext $context): JsonResponse
    {
        $validated = $request->validate([
            'ver' => ['nullable', 'in:consulta,reporte,accion'],
            'clave' => ['required_with:ver', 'nullable', 'string', 'max:60'],
        ]);

        if (isset($validated['ver'])) {
            return $this->respond($this->detail($context, $validated['ver'], $validated['clave']));
        }

        return $this->respond($this->summary($context));
    }

    private function summary(ContiContext $context): array
    {
        $company = $context->company();
        $license = $company->license;

        $screens = [];
        foreach ($context->levels() as $key => $level) {
            if ($level !== 'none') {
                $screens[] = ContiContext::screenPath($key).($level === 'read_write' ? ' (lectura y escritura)' : ' (lectura)');
            }
        }

        return [
            'persona' => ['nombre' => $context->user()->name, 'rol' => $context->roleLabel()],
            'compania' => [
                'nombre' => $company->trade_name ?: $company->legal_name,
                'razon_social' => $company->legal_name,
                'cedula_juridica' => $company->tax_id,
                'moneda_local' => Currency::find($company->local_currency_id)?->code,
                'moneda_extranjera' => $company->foreign_currency_id ? Currency::find($company->foreign_currency_id)?->code : null,
            ],
            'licencia' => [
                'vence' => $license?->expires_at?->format('Y-m-d'),
                'modo_gracia' => $context->inGrace(),
                'nota' => $context->inGrace() ? 'La licencia está vencida: se puede consultar, pero no guardar nada.' : null,
            ],
            'fecha_hoy' => now()->format('Y-m-d'),
            'permisos' => $screens,
            'consultas' => array_map(fn (ContiResource $r) => "{$r->key}: {$r->label}", ContiResourceCatalog::availableTo($context)),
            'reportes' => array_map(fn (ContiReport $r) => "{$r->key}: {$r->label}", ContiReportCatalog::availableTo($context)),
            'acciones' => $context->inGrace() ? [] : array_map(fn (ContiAction $a) => "{$a->key()}: {$a->label()}", ContiActionCatalog::availableTo($context)),
            'como_seguir' => 'Para los filtros de una consulta, los parámetros de un reporte o los campos de una acción, pedí contexto con ver=consulta|reporte|accion y clave=….',
        ];
    }

    private function detail(ContiContext $context, string $kind, string $key): array
    {
        return match ($kind) {
            'consulta' => $this->resource($context, $key),
            'reporte' => $this->report($context, $key),
            default => $this->action($context, $key),
        };
    }

    private function resource(ContiContext $context, string $key): array
    {
        $resource = ContiResourceCatalog::find($key) ?? abort(404, "No existe la consulta «{$key}».");

        if ($resource->screens !== []) {
            $context->authorize($resource->screens);
        }

        return $resource->describe();
    }

    private function report(ContiContext $context, string $key): array
    {
        $report = ContiReportCatalog::find($key) ?? abort(404, "No existe el reporte «{$key}».");
        $context->authorize($report->screens);

        return $report->describe();
    }

    private function action(ContiContext $context, string $key): array
    {
        $action = ContiActionCatalog::find($key) ?? abort(404, "No existe la acción «{$key}».");
        $context->authorize($action->screen(), 'read_write');

        return [
            'accion' => $action->key(),
            'nombre' => $action->label(),
            'descripcion' => $action->description(),
            'datos' => $action->fields(),
        ];
    }
}
