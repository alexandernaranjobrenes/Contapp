<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Exceptions\HaciendaApiException;
use App\Domains\Billing\Exceptions\InvalidElectronicTransitionException;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentDelivery;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentPdf;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentReissuer;
use App\Domains\Billing\Services\Hacienda\ElectronicPreflight;
use App\Domains\Billing\Services\Hacienda\HaciendaSubmissionService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo que se hace con un comprobante ante Hacienda desde su pantalla
 * (CLAUDE.md secc. 38): consultar su estado, reintentar un envío que no se
 * confirmó, volver a emitir uno rechazado con clave nueva, reenviarlo al
 * cliente, y descargar el PDF y la respuesta de Hacienda.
 *
 * Consultar y reintentar corren en el momento (una sola llamada, con el
 * tiempo de espera de config/hacienda.php) para que quien lo pide vea el
 * resultado; lo automático lo hace la cola. Las reglas —qué se puede reenviar
 * y qué no— son de HaciendaSubmissionService: acá solo se traducen a un
 * mensaje.
 */
class ElectronicDocumentController extends Controller
{
    public function __construct(
        private readonly HaciendaSubmissionService $submissions,
    ) {}

    public function check(Request $request, int $salesDocument): RedirectResponse
    {
        $electronic = $this->current($salesDocument);

        return $this->run(fn () => $this->submissions->poll($electronic, $request->user()->id), $electronic, 'Estado consultado en Hacienda');
    }

    public function retry(Request $request, int $salesDocument): RedirectResponse
    {
        $electronic = $this->current($salesDocument);

        if (! in_array($electronic->status, [ElectronicDocument::SIGNED, ElectronicDocument::SUBMIT_UNKNOWN, ElectronicDocument::NEEDS_ATTENTION], true)) {
            return back()->with('error', "El comprobante está «{$electronic->label()}»: no hay un envío que reintentar.");
        }

        return $this->run(fn () => $this->submissions->submit($electronic, $request->user()->id), $electronic, 'Envío reintentado');
    }

    public function reissue(Request $request, int $salesDocument, CurrentCompany $currentCompany, ElectronicDocumentReissuer $reissuer, ElectronicPreflight $preflight): RedirectResponse
    {
        $document = SalesDocument::findOrFail($salesDocument);
        $company = Company::findOrFail($currentCompany->id());

        // La reemisión lleva el CAByS y la actividad guardados en la venta: si
        // Hacienda ya dijo que alguno no sirve, se rechazaría otra vez con un
        // consecutivo más.
        $problems = $preflight->problemsForDocument($company, $document);

        if ($problems !== []) {
            return back()->with('error', 'No se emitió de nuevo: '.implode(' ', $problems));
        }

        try {
            $new = $reissuer->reissue($company, $document, $request->user()->id);
        } catch (InvalidSalesDocumentException|InvalidElectronicTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Se emitió de nuevo con el consecutivo {$new->consecutive} y una clave nueva; va en camino a Hacienda.");
    }

    public function deliver(Request $request, int $salesDocument, ElectronicDocumentDelivery $delivery): RedirectResponse
    {
        $electronic = $this->current($salesDocument);

        $validated = $request->validate([
            'emails' => ['nullable', 'array', 'max:4'],
            'emails.*' => ['required', 'email', 'max:160'],
        ]);

        if ($electronic->status !== ElectronicDocument::ACCEPTED) {
            return back()->with('error', 'Solo se envía al cliente un comprobante que Hacienda aceptó.');
        }

        // Sin correos, al del cliente; con correos, a esos (reenviar a otra persona).
        $emails = $validated['emails'] ?? [];
        $sent = $delivery->deliver($electronic, $emails === [] ? null : $emails);

        return $sent
            ? back()->with('success', 'Comprobante enviado a '.implode(', ', (array) $electronic->fresh()->delivery_recipients).'.')
            : back()->with('error', $electronic->fresh()->delivery_error ?? 'No se pudo enviar el comprobante.');
    }

    public function pdf(int $salesDocument, CurrentCompany $currentCompany, ElectronicDocumentPdf $pdf): Response
    {
        $document = SalesDocument::findOrFail($salesDocument);

        return $pdf->pdf(Company::findOrFail($currentCompany->id()), $document)->download($pdf->filename($document));
    }

    /** El MensajeHacienda con que Hacienda aceptó o rechazó el comprobante. */
    public function response(int $salesDocument): StreamedResponse
    {
        $electronic = $this->current($salesDocument);

        abort_if(blank($electronic->response_xml), 404, 'Hacienda todavía no envió su respuesta para este comprobante.');

        return response()->streamDownload(
            fn () => print ($electronic->response_xml),
            "{$electronic->clave}-respuesta.xml",
            ['Content-Type' => 'application/xml'],
        );
    }

    private function current(int $salesDocument): ElectronicDocument
    {
        // Por el comprobante de la compañía activa (el scope lo garantiza).
        $document = SalesDocument::findOrFail($salesDocument);

        return ElectronicDocument::where('sales_document_id', $document->id)->orderByDesc('id')->first()
            ?? abort(404, 'Este comprobante no se emitió electrónicamente.');
    }

    /** @param  callable(): bool  $action */
    private function run(callable $action, ElectronicDocument $electronic, string $done): RedirectResponse
    {
        try {
            $ran = $action();
        } catch (HaciendaApiException|InvalidElectronicTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $ran) {
            return back()->with('error', 'El comprobante se está procesando en este momento: esperá unos segundos y volvé a consultar.');
        }

        $electronic->refresh();
        $detail = $electronic->hacienda_message ? ' — '.$electronic->hacienda_message : '';

        return back()->with(
            in_array($electronic->status, [ElectronicDocument::REJECTED, ElectronicDocument::SUBMIT_FAILED, ElectronicDocument::NEEDS_ATTENTION], true) ? 'error' : 'success',
            "{$done}: {$electronic->label()}{$detail}"
        );
    }
}
