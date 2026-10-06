<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Conti\Services\ContiActionService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla en la que la persona revisa lo que Conti preparó y decide:
 * «Confirmar y guardar» o «Descartar» (Conti/Action.vue). Es el único camino
 * por el que Conti escribe algo, y lo recorre la persona con su sesión.
 */
class ContiActionController extends Controller
{
    public function show(Request $request, string $uuid): Response
    {
        $pending = $this->find($request, $uuid);
        $action = ContiActionCatalog::find($pending->action);
        $status = $pending->effectiveStatus();

        return Inertia::render('Conti/Action', [
            'action' => [
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
            ],
        ]);
    }

    public function confirm(Request $request, ContiActionService $service, string $uuid): RedirectResponse
    {
        $pending = $this->find($request, $uuid);

        if ($other = $this->otherCompany($pending)) {
            return back()->with('error', $other);
        }

        $pending = $service->confirm($pending, $request->user());

        return match ($pending->status) {
            'confirmed' => back()->with('success', $pending->result['mensaje'] ?? 'Guardado.'),
            'failed' => back()->with('error', 'No se guardó: '.$pending->error),
            default => back(),
        };
    }

    public function discard(Request $request, ContiActionService $service, string $uuid): RedirectResponse
    {
        $pending = $this->find($request, $uuid);

        if ($other = $this->otherCompany($pending)) {
            return back()->with('error', $other);
        }

        $service->discard($pending, $request->user());

        return back()->with('success', 'Descartado: no se guardó nada.');
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
