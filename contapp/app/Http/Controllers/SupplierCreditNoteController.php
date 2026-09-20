<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\PurchaseReturnLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Services\PostSupplierCreditNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nota de crédito de proveedor. Se llega acá desde el "Copiar a" de una
 * recepción ya facturada: el documento de origen define qué se puede devolver,
 * a qué precio se compró y contra qué factura se acredita.
 */
class SupplierCreditNoteController extends Controller
{
    public function __construct(private readonly PostSupplierCreditNoteService $service) {}

    public function create(int $inventoryDocument): Response|RedirectResponse
    {
        $receipt = InventoryDocument::with([
            'lines.item:id,code,name',
            'lines.warehouse:id,code',
            'businessPartner:id,code,name',
            'invoiceJournalEntry:id,document_number',
        ])->findOrFail($inventoryDocument);

        // Solo se devuelve lo que se compró y ya se facturó: antes de la
        // factura la cuenta puente todavía no tiene contra qué acreditarse, y
        // lo correcto es anular la recepción, no emitir una nota.
        if ($receipt->operation !== 'purchase_receipt' || $receipt->status !== 'posted') {
            return redirect()->route('inventory-movements.show', $receipt->id)
                ->withErrors(['credit_note' => 'Solo una entrada por compra contabilizada admite nota de crédito.']);
        }

        if ($receipt->invoice_journal_entry_id === null) {
            return redirect()->route('inventory-movements.show', $receipt->id)
                ->withErrors(['credit_note' => 'La recepción aún no está facturada: primero se emite la factura del proveedor.']);
        }

        $capacity = $this->capacityByLine($receipt);

        return Inertia::render('Inventory/SupplierCreditNotes/Create', [
            'receipt' => [
                'id' => $receipt->id,
                'posting_date' => $receipt->posting_date->format('Y-m-d'),
                'operation' => $receipt->operation,
                'supplier' => $receipt->businessPartner
                    ? $receipt->businessPartner->code.' — '.$receipt->businessPartner->name
                    : null,
                'invoice_document_number' => $receipt->invoiceJournalEntry?->document_number,
                'invoice_journal_entry_id' => $receipt->invoice_journal_entry_id,
            ],
            'lines' => $receipt->lines->map(fn ($line) => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'item' => $line->item?->code.' — '.$line->item?->name,
                'warehouse_code' => $line->warehouse?->code,
                'quantity' => $line->quantity,
                'unit_cost_local' => $line->unit_cost_local,
                'returned' => $capacity[$line->id]['returned'],
                'pending' => $capacity[$line->id]['pending'],
            ])->values(),
            'documentTypes' => DocumentType::where('origin_module', 'compras')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'taxAccounts' => ChartOfAccount::whereNotNull('tax_rate_id')
                ->where('accepts_posting', true)
                ->with('taxRate:id,code,percentage')
                ->orderBy('code')
                ->get(['id', 'code', 'description_es', 'tax_rate_id'])
                ->map(fn (ChartOfAccount $a) => [
                    'id' => $a->id,
                    'label' => $a->code.' — '.$a->description_es,
                    'percentage' => $a->taxRate?->percentage,
                ])->values(),
        ]);
    }

    public function store(Request $request, int $inventoryDocument, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $receipt = InventoryDocument::findOrFail($inventoryDocument);

        $validated = $request->validate([
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')
                    ->where('company_id', $companyId)
                    ->where('origin_module', 'compras'),
            ],
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'tax_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.receipt_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.credited_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn (array $line) => new PurchaseReturnLineInput(
            receiptLineId: (int) $line['receipt_line_id'],
            quantity: $line['quantity'],
            creditedUnitPrice: $line['credited_unit_price'] ?? null,
        ), $validated['lines']);

        try {
            $document = $this->service->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                receipt: $receipt,
                documentDate: new \DateTimeImmutable($validated['posting_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                lines: $lines,
                taxAccountId: $validated['tax_account_id'] ?? null,
                taxAmount: $validated['tax_amount'] ?? 0,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            // Toda excepción de dominio ya revirtió salida de mercancía y nota:
            // acá solo se traduce a un mensaje de formulario.
            return back()->withErrors(['credit_note' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory-movements.show', $document->id)
            ->with('success', 'Nota de crédito registrada; la mercancía salió del inventario y la deuda se redujo.');
    }

    /**
     * Cuánto queda por devolver de cada línea. El tope lo lleva el servicio por
     * artículo y almacén —no por línea suelta—, así que acá se agrupa igual:
     * si la recepción trae el mismo artículo dos veces, ambas comparten el
     * mismo saldo devolvible.
     *
     * @return array<int, array{received: string, returned: string, pending: string}>
     */
    private function capacityByLine(InventoryDocument $receipt): array
    {
        $received = [];
        $returned = [];

        foreach ($receipt->lines as $line) {
            $key = $line->item_id.':'.$line->warehouse_id;
            $received[$key] = bcadd($received[$key] ?? '0.000000', (string) $line->quantity, 6);
        }

        foreach ($receipt->returns()->with('lines')->get() as $return) {
            foreach ($return->lines as $line) {
                $key = $line->item_id.':'.$line->warehouse_id;
                $returned[$key] = bcadd($returned[$key] ?? '0.000000', (string) $line->quantity, 6);
            }
        }

        $byLine = [];

        foreach ($receipt->lines as $line) {
            $key = $line->item_id.':'.$line->warehouse_id;

            $byLine[$line->id] = [
                'received' => $received[$key],
                'returned' => $returned[$key] ?? '0.000000',
                'pending' => bcsub($received[$key], $returned[$key] ?? '0.000000', 6),
            ];
        }

        return $byLine;
    }
}
