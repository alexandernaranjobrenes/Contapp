<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\LandedCostDocument;
use App\Domains\Inventory\Services\PostLandedCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LandedCostController extends Controller
{
    public function __construct(private readonly PostLandedCostService $postLandedCostService) {}

    public function index(): Response
    {
        $receipts = InventoryDocument::whereIn('operation', ['goods_receipt', 'purchase_receipt'])
            ->where('status', 'posted')
            ->with(['documentType:id,code', 'businessPartner:id,code,name'])
            ->with(['lines.stockJournals:id,inventory_document_line_id,total_cost_local,direction'])
            ->orderByDesc('posting_date')
            ->limit(100)
            ->get();

        return Inertia::render('Inventory/LandedCosts/Index', [
            'receipts' => $receipts->map(fn (InventoryDocument $d) => [
                'id' => $d->id,
                'posting_date' => $d->posting_date->format('Y-m-d'),
                'document_type_code' => $d->documentType?->code,
                'supplier' => $d->businessPartner ? $d->businessPartner->code.' — '.$d->businessPartner->name : null,
                'lines_count' => $d->lines->count(),
                'total_local' => $d->lines
                    ->flatMap->stockJournals
                    ->where('direction', 'in')
                    ->sum(fn ($m) => (float) $m->total_cost_local),
            ])->values(),
            'documents' => LandedCostDocument::with([
                'businessPartner:id,code,name',
                'receipt:id,posting_date',
                'journalEntry:id,document_number',
            ])
                ->orderByDesc('posting_date')
                ->limit(100)
                ->get()
                ->map(fn (LandedCostDocument $d) => [
                    'id' => $d->id,
                    'posting_date' => $d->posting_date->format('Y-m-d'),
                    'supplier' => $d->businessPartner?->code.' — '.$d->businessPartner?->name,
                    'receipt_id' => $d->inventory_document_id,
                    'amount' => $d->amount,
                    'capitalized_amount' => $d->capitalized_amount,
                    'expensed_amount' => $d->expensed_amount,
                    'description' => $d->description,
                    'journal_document_number' => $d->journalEntry?->document_number,
                ])->values(),
            'documentTypes' => DocumentType::where('origin_module', 'compras')
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
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
            'business_partner_id' => [
                'required',
                Rule::exists('business_partners', 'id')
                    ->where('company_id', $companyId)
                    ->whereIn('type', ['supplier', 'both']),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->postLandedCostService->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                receipt: InventoryDocument::findOrFail($validated['inventory_document_id']),
                businessPartnerId: (int) $validated['business_partner_id'],
                amount: $validated['amount'],
                documentDate: new \DateTimeImmutable($validated['document_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                dueDate: isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['landed_cost' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Costo de importación aplicado.');
    }
}
