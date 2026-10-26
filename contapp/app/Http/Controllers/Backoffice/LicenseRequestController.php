<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Pricing\Models\LicenseRequest;
use App\Domains\Pricing\Models\LicenseRequestReply;
use App\Domains\Pricing\Services\LicenseRequestService;
use App\Domains\Pricing\Support\PricingMoney;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use App\Http\Controllers\Controller;
use App\Notifications\LicenseRequestReplyNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las solicitudes de licencia que llegan desde /precios (CLAUDE.md secc.
 * 42). De cada una, el backoffice decide: responderla por correo, o
 * descartarla sin responder. Se puede reabrir. Cada decisión queda en la
 * bitácora del backoffice, y cada correo en el historial de la solicitud.
 */
class LicenseRequestController extends Controller
{
    use RecordsPropietarioAudit;

    /** Las que se muestran: las más nuevas; lo de antes queda en la base. */
    private const LIMIT = 500;

    public function __construct(private readonly LicenseRequestService $requests) {}

    public function index(Request $request): Response
    {
        $requests = LicenseRequest::query()
            ->with(['handler:id,name', 'replies.propietario:id,name'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (LicenseRequest $item) => [
                'id' => $item->id,
                'reference' => $item->reference(),
                'status' => $item->status,
                'contact_name' => $item->contact_name,
                'company_name' => $item->company_name,
                'identification' => $item->identification,
                'email' => $item->email,
                'phone' => $item->phone,
                'message' => $item->message,
                'package_name' => $item->package_name,
                'cycle' => PricingMoney::cycle($item->billing_cycle),
                'currency' => $item->currency,
                'total' => PricingMoney::format((int) round((float) $item->total * 100), $item->currency),
                'lines' => LicenseRequestReplyNotification::lines($item->quote),
                'totals' => LicenseRequestReplyNotification::totals($item->quote),
                'internal_note' => $item->internal_note,
                'handled_by' => $item->handler?->name,
                'handled_at' => $item->handled_at?->toIso8601String(),
                'created_at' => $item->created_at->toIso8601String(),
                'replies' => $item->replies->map(fn (LicenseRequestReply $reply) => [
                    'id' => $reply->id,
                    'subject' => $reply->subject,
                    'body' => $reply->body,
                    'include_quote' => $reply->include_quote,
                    'status' => $reply->status,
                    'error' => $reply->error,
                    'by' => $reply->propietario?->name,
                    'created_at' => $reply->created_at->toIso8601String(),
                ])->all(),
            ]);

        return Inertia::render('Backoffice/LicenseRequests/Index', [
            'requests' => $requests,
            // El enlace del correo de aviso abre la solicitud.
            'openId' => $request->integer('solicitud') ?: null,
            'contactEmail' => config('site.contact_email'),
        ]);
    }

    public function reply(Request $request, LicenseRequest $licenseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'include_quote' => ['boolean'],
        ], [], ['subject' => 'asunto', 'body' => 'mensaje']);

        $sent = $this->requests->reply(
            $licenseRequest,
            $request->user('propietario'),
            trim($validated['subject']),
            trim($validated['body']),
            (bool) ($validated['include_quote'] ?? false),
        );

        $this->auditPropietario($request, $sent ? 'license_request_replied' : 'license_request_reply_failed', $licenseRequest, null, [
            'para' => $licenseRequest->email,
            'asunto' => $validated['subject'],
        ]);

        return $sent
            ? back()->with('success', "La respuesta salió a {$licenseRequest->email}.")
            : back()->with('error', 'No se pudo enviar el correo: quedó anotado en el historial y la solicitud sigue sin responder. Probá de nuevo en un momento.');
    }

    public function discard(Request $request, LicenseRequest $licenseRequest): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']], [], ['note' => 'nota']);

        $this->requests->discard($licenseRequest, $request->user('propietario'), $validated['note'] ?? null);
        $this->auditPropietario($request, 'license_request_discarded', $licenseRequest, null, ['nota' => $validated['note'] ?? null]);

        return back()->with('success', "La solicitud {$licenseRequest->reference()} quedó descartada, sin responder.");
    }

    public function reopen(Request $request, LicenseRequest $licenseRequest): RedirectResponse
    {
        $this->requests->reopen($licenseRequest);
        $this->auditPropietario($request, 'license_request_reopened', $licenseRequest);

        return back()->with('success', "La solicitud {$licenseRequest->reference()} volvió a estar pendiente.");
    }
}
