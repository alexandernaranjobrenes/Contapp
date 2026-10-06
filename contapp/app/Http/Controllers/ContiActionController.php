<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Conti\Services\ContiActionService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Donde la persona revisa lo que Conti preparó y decide: «Confirmar y
 * guardar» o «Descartar». Es el único camino por el que Conti escribe algo, y
 * lo recorre la persona con su sesión.
 *
 * Dos formas, con las mismas reglas:
 * - el modal que abre el enlace del chat, sin salir de la pantalla en que se
 *   está (ContiActionModal.vue): pide y recibe JSON;
 * - la pantalla Conti/Action.vue, para el enlace abierto en otra pestaña o
 *   recargado: Inertia.
 */
class ContiActionController extends Controller
{
    public function show(Request $request, string $uuid): Response|JsonResponse
    {
        $pending = $this->find($request, $uuid);

        if ($request->wantsJson()) {
            return response()->json(['action' => $this->present($pending)]);
        }

        return Inertia::render('Conti/Action', ['action' => $this->present($pending)]);
    }

    public function confirm(Request $request, ContiActionService $service, string $uuid): RedirectResponse|JsonResponse
    {
        $pending = $this->find($request, $uuid);

        if ($other = $this->otherCompany($pending)) {
            return $request->wantsJson() ? response()->json(['message' => $other], 409) : back()->with('error', $other);
        }

        $pending = $service->confirm($pending, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['action' => $this->present($pending)]);
        }

        return match ($pending->status) {
            'confirmed' => back()->with('success', $pending->result['mensaje'] ?? 'Guardado.'),
            'failed' => back()->with('error', 'No se guardó: '.$pending->error),
            default => back(),
        };
    }

    public function discard(Request $request, ContiActionService $service, string $uuid): RedirectResponse|JsonResponse
    {
        $pending = $this->find($request, $uuid);

        if ($other = $this->otherCompany($pending)) {
            return $request->wantsJson() ? response()->json(['message' => $other], 409) : back()->with('error', $other);
        }

        $pending = $service->discard($pending, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['action' => $this->present($pending)]);
        }

        return back()->with('success', 'Descartado: no se guardó nada.');
    }

    /** Lo que la persona ve de la acción, en el modal o en la pantalla. */
    private function present(ContiAction $pending): array
    {
        $action = ContiActionCatalog::find($pending->action);
        $status = $pending->effectiveStatus();

        return [
            'uuid' => $pending->uuid,
            'label' => $action?->label() ?? $pending->action,
            'screen' => $action ? ContiContext::screenPath($action->screen()) : null,
            'status' => $status,
            'status_label' => ContiAction::STATUSES[$status] ?? $status,
            'summary' => $pending->summary,
            'result' => $pending->result,
            'error' => $pending->error,
            'created_at' => $pending->localTime($pending->created_at),
            'expires_at' => $pending->localTime($pending->expires_at),
            'decided_at' => $pending->localTime($pending->decided_at),
            'company' => $pending->company?->trade_name ?: $pending->company?->legal_name,
            'other_company' => $pending->company_id !== app(CurrentCompany::class)->id(),
        ];
    }

    /** Se decide en la compañía en la que se preparó. */
    private function otherCompany(ContiAction $pending): ?string
    {
        if ($pending->company_id === app(CurrentCompany::class)->id()) {
            return null;
        }

        $name = $pending->company?->trade_name ?: $pending->company?->legal_name;

        return "Esto es de {$name}: cambiá a esa compañía para confirmarlo o descartarlo.";
    }

    /** Solo lo de esta persona: lo de otra, como si no existiera. */
    private function find(Request $request, string $uuid): ContiAction
    {
        return ContiAction::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();
    }
}
