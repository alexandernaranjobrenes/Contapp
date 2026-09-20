<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Services\PostSupplierInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierInvoiceController extends Controller
{
    public function __construct(private readonly PostSupplierInvoiceService $postSupplierInvoiceService) {}

    /**
     * Bandeja de recepciones pendientes de facturar: el saldo vivo de la
     * cuenta puente GR/IR, visto desde el lado logístico.
     */
    public function index(Request $request): Response
    {
        $pending = InventoryDocument::pendingInvoice()
            ->with(['businessPartner:id,code,name', 'documentType:id,code', 'journalEntry:id,document_number'])
            ->with(['lines.stockJournals:id,inventory_document_line_id,total_cost_local'])
            ->orderBy('posting_date')
            ->get();

        // El "Copiar a" de una recepción llega con ?receipt=N: la bandeja es la
        // misma, pero abre el formulario ya apuntando a ese documento. Si la
        // recepción ya no está pendiente, el parámetro se ignora.
        $preselected = $request->integer('receipt') ?: null;

        return Inertia::render('Inventory/SupplierInvoices/Index', [
            'preselected' => $pending->contains('id', $preselected) ? $preselected : null,
            'pending' => $pending->map(fn (InventoryDocument $d) => [
                'id' => $d->id,
                'posting_date' => $d->posting_date->format('Y-m-d'),
                'document_type_code' => $d->documentType?->code,
                'journal_document_number' => $d->journalEntry?->document_number,
                'supplier' => $d->businessPartner?->code.' — '.$d->businessPartner?->name,
                'lines_count' => $d->lines->count(),
                'total_local' => $d->lines
                    ->flatMap->stockJournals
                    ->sum(fn ($m) => (float) $m->total_cost_local),
            ])->values(),
            'invoiceTypes' => DocumentType::where('origin_module', 'compras')
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            // Solo cuentas con indicador de impuesto vinculado: son las únicas
            // que pueden recibir el IVA de una factura (chart_of_accounts.tax_rate_id).
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

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'inventory_document_id' => [
                'required',
                Rule::exists('inventory_documents', 'id')->where('company_id', $companyId),
            ],
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')
                    ->where('company_id', $companyId)
                    ->where('origin_module', 'compras'),
            ],
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'tax_account_id' => [
                'nullable',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId),
            ],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            // Neto realmente facturado. Si difiere del valor recibido, la
            // diferencia se capitaliza o va a resultados según cuánta
            // mercancía siga en existencia (Fase 5).
            'net_amount' => ['nullable', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->postSupplierInvoiceService->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                receipt: InventoryDocument::findOrFail($validated['inventory_document_id']),
                documentDate: new \DateTimeImmutable($validated['document_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                taxAccountId: $validated['tax_account_id'] ?? null,
                taxAmount: $validated['tax_amount'] ?? 0,
                dueDate: isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
                netAmount: $validated['net_amount'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory-movements.show', $validated['inventory_document_id'])
            ->with('success', 'Factura de proveedor contabilizada; la cuenta puente quedó liquidada.');
    }
}
