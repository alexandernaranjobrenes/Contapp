<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Agent\ContiToolbox;
use App\Domains\Conti\Services\ContiActionService;
use App\Domains\Conti\Services\ContiFormService;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiHistory;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los formularios que Conti muestra en el chat (ContiForm.vue, CLAUDE.md
 * secc. 32).
 *
 * - submit: la persona envía el formulario. Se prepara la acción como
 *   siempre (ContiActionService::prepare: permisos, licencia, validación y
 *   prueba en seco) y el chat abre la ventana para confirmarla. No pasa por
 *   el modelo: no consume créditos.
 * - options: las sugerencias de un campo «buscar» (cuentas, socios,
 *   artículos…), de las consultas de Conti, con los permisos de la persona.
 * - suggest: lo que CONTAPP sugiere para los campos vacíos, según cómo se
 *   viene trabajando, cuando cambia un campo clave del formulario.
 */
class ContiFormController extends Controller
{
    public function submit(
        Request $request,
        CurrentCompany $currentCompany,
        ContiUsageService $usage,
        ContiContext $context,
        ContiActionService $actions,
        ContiFormService $forms,
    ): JsonResponse {
        $validated = $request->validate([
            'accion' => ['required', 'string', 'max:60'],
            'datos' => ['present', 'array'],
            'sesion' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $user = $request->user();
        $company = $this->company($currentCompany, $usage, $user);
        $context->set($user, $company, $currentCompany->isInGracePeriod());

        $pending = $actions->prepare($context, $validated['accion'], $forms->submission($validated['accion'], $validated['datos'], $company));
        $title = $pending->summary['titulo'] ?? $pending->action;

        // Para que Conti sepa, en el mensaje siguiente, qué se envió.
        if (filled($validated['sesion'] ?? null)) {
            ContiHistory::append(
                $user->id,
                $company->id,
                $validated['sesion'],
                ['role' => 'user', 'content' => "(Completé el formulario y lo envié: quedó preparado «{$title}».)"],
                ['role' => 'assistant', 'content' => "(CONTAPP le abrió la ventana para confirmar «{$title}» (id {$pending->uuid}).)"],
            );
        }

        return response()->json(['accion' => ['id' => $pending->uuid, 'titulo' => $title]], 201);
    }

    public function suggest(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context, ContiFormService $forms): JsonResponse
    {
        $validated = $request->validate([
            'accion' => ['required', 'string', 'max:60'],
            'valores' => ['present', 'array'],
        ]);

        $user = $request->user();
        $company = $this->company($currentCompany, $usage, $user);
        $context->set($user, $company, $currentCompany->isInGracePeriod());

        return response()->json(['sugeridos' => (object) $forms->suggestions($context, $validated['accion'], $validated['valores'])])
            ->header('Cache-Control', 'no-store');
    }

    public function options(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiContext $context, ContiToolbox $tools): JsonResponse
    {
        $validated = $request->validate([
            'fuente' => ['required', 'string', Rule::in(array_keys(ContiFormService::SOURCES))],
            'q' => ['nullable', 'string', 'max:100'],
            'filtros' => ['nullable', 'array'],
            'filtros.*' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        $company = $this->company($currentCompany, $usage, $user);
        $context->set($user, $company, $currentCompany->isInGracePeriod());

        $source = ContiFormService::SOURCES[$validated['fuente']];
        $result = $tools->records($validated['fuente'], [
            ...($validated['filtros'] ?? []),
            'buscar' => trim((string) ($validated['q'] ?? '')),
            'limite' => 15,
        ]);

        return response()->json([
            'opciones' => array_values(array_filter(array_map(function (array $row) use ($source) {
                $value = $row[$source['valor']] ?? null;
                $label = collect($source['muestra'])->map(fn (string $f) => $row[$f] ?? null)->filter(fn ($v) => $v !== null && $v !== '')->implode(' · ');

                return $value === null || $value === '' ? null : ['valor' => (string) $value, 'etiqueta' => $label];
            }, $result['registros']))),
        ])->header('Cache-Control', 'no-store');
    }

    /** La compañía activa, si en ella hay Conti para esta persona. */
    private function company(CurrentCompany $currentCompany, ContiUsageService $usage, User $user): Company
    {
        $companyId = $currentCompany->id() ?? abort(409, 'Elegí una compañía para usar Conti.');
        $company = Company::with('license')->findOrFail($companyId);

        abort_unless($usage->enabledFor($company, $user), 403, 'No tenés acceso a Conti en esta compañía.');

        return $company;
    }
}
