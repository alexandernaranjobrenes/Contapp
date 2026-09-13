<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\BusinessPartners\Services\OpenItemNettingService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OpenItemController extends Controller
{
    /**
     * $businessPartner se recibe como id crudo, no como binding implícito de
     * Eloquent: el binding implícito resuelve vía SubstituteBindings, cuyo
     * orden relativo a nuestro SetCurrentCompany (que setea el CompanyScope)
     * no quedó garantizado ni siquiera declarando la prioridad explícita
     * (ver docs/decisiones.md 2026-08-05). Resolver a mano dentro del
     * controlador es inmune a ese problema, porque el controlador siempre
     * corre después de todo el pipeline de middleware.
     */
    public function index(int $businessPartner): Response
    {
        $businessPartner = BusinessPartner::findOrFail($businessPartner);

        $openItems = $businessPartner->openItems()
            ->with(['currency:id,code,symbol', 'originJournalDetail:id,debit_local,credit_local'])
            ->orderByDesc('due_date')
            ->get();

        return Inertia::render('BusinessPartners/OpenItems', [
            'partner' => $businessPartner,
            'openItems' => $openItems->map(fn (BpOpenItem $item) => [
                ...$item->toArray(),
                // Para poder sumar varias partidas en el navegador (selector
                // de reconciliación interna) sin adivinar signos ahí — mismo
                // criterio que ya usa OpenItemNettingService::reconcile().
                'signed_balance' => $item->isOpen() ? $item->signedBalance() : '0.00',
            ]),
            'paymentAccounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
        ]);
    }

    public function applyPayment(
        Request $request,
        int $businessPartner,
        int $openItem,
        CurrentCompany $currentCompany,
        PostJournalService $postJournalService,
        ApplyPaymentService $applyPaymentService,
    ): RedirectResponse {
        $businessPartner = BusinessPartner::findOrFail($businessPartner);
        $openItem = BpOpenItem::findOrFail($openItem);

        abort_unless($openItem->business_partner_id === $businessPartner->id, 404);

        $validated = $request->validate([
            'payment_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'applied_date' => ['required', 'date'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $paymentDocumentType = DocumentType::where('code', 'TRB')->first()
            ?? DocumentType::where('generates_journal', true)->firstOrFail();

        try {
            $paymentEntry = $postJournalService->post(
                $company,
                $paymentDocumentType,
                new \DateTime($validated['applied_date']),
                new \DateTime($validated['applied_date']),
                [
                    new JournalLineInput(
                        (int) $validated['payment_account_id'],
                        $openItem->currency_id,
                        debit: $businessPartner->isSupplier() ? $validated['amount'] : 0,
                        credit: $businessPartner->isSupplier() ? 0 : $validated['amount'],
                    ),
                    new JournalLineInput(
                        $businessPartner->gl_account_id,
                        $openItem->currency_id,
                        debit: $businessPartner->isSupplier() ? 0 : $validated['amount'],
                        credit: $businessPartner->isSupplier() ? $validated['amount'] : 0,
                        businessPartnerId: $businessPartner->id,
                    ),
                ],
                "Aplicación de pago — {$businessPartner->code}",
                $request->user()->id,
            );

            $applyPaymentService->apply(
                $openItem,
                $paymentEntry,
                $validated['amount'],
                new \DateTime($validated['applied_date']),
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Pago aplicado.');
    }

    /**
     * Corrige el vencimiento de una partida ya abierta, SIN tocar el asiento
     * que la originó — un asiento contabilizado nunca se altera (regla
     * innegociable de CLAUDE.md), pero un vencimiento mal tipeado a mano no
     * puede quedar corrompiendo para siempre la cédula de antigüedad de
     * saldos y el análisis de vencimientos (ambos leen bp_open_items.due_date,
     * nunca journal_details.due_date directamente). Esta es la única fila
     * "viva" en este circuito — ya se actualiza aparte para aplicar pagos —
     * así que es el lugar correcto para la corrección, no el asiento.
     */
    public function updateDueDate(Request $request, int $businessPartner, int $openItem): RedirectResponse
    {
        $businessPartner = BusinessPartner::findOrFail($businessPartner);
        $openItem = BpOpenItem::findOrFail($openItem);

        abort_unless($openItem->business_partner_id === $businessPartner->id, 404);

        if ($openItem->status === 'closed') {
            return back()->withErrors(['due_date' => 'Una partida ya cerrada no se puede corregir: no participa en ningún reporte de antigüedad de saldos.']);
        }

        $validated = $request->validate([
            'due_date' => ['required', 'date'],
        ]);

        $openItem->update(['due_date' => $validated['due_date']]);

        return back()->with('success', 'Vencimiento corregido.');
    }

    /**
     * Reconciliación interna: cierra entre sí varias partidas del MISMO
     * socio cuyo saldo pendiente neta a cero (ver OpenItemNettingService) —
     * ningún asiento se contabiliza, solo se cierra el hueco de seguimiento
     * cuando dos partidas en realidad ya se cancelaban (ej. un saldo inicial
     * y un pago que ya estaba contabilizado por fuera de "Aplicar pago").
     */
    public function reconcile(Request $request, int $businessPartner, OpenItemNettingService $service): RedirectResponse
    {
        $businessPartner = BusinessPartner::findOrFail($businessPartner);

        $validated = $request->validate([
            'open_item_ids' => ['required', 'array', 'min:2'],
            'open_item_ids.*' => ['integer', Rule::exists('bp_open_items', 'id')->where('business_partner_id', $businessPartner->id)],
        ]);

        try {
            $service->reconcile($validated['open_item_ids'], $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return back()->with('success', 'Partidas reconciliadas entre sí.');
    }
}
