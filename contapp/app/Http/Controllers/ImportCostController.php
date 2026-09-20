<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\ImportCostDocument;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Services\PostImportCostService;
use App\Domains\Inventory\Services\PostLandedCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Costos de nacionalización en dos fases: acumular el rubro de cada proveedor
 * y después asignarlo a una o varias importaciones.
 */
class ImportCostController extends Controller
{
    public function __construct(
        private readonly PostImportCostService $importCostService,
        private readonly PostLandedCostService $landedCostService,
    ) {}

    /**
     * Bandeja de rubros: el saldo vivo de la cuenta transitoria, visto rubro
     * por rubro y con su proveedor.
     */
    public function index(): Response
    {
        $documents = ImportCostDocument::with([
            'businessPartner:id,code,name',
            'journalEntry:id,document_number',
            'allocations.landedCost:id,inventory_document_id,posting_date',
        ])->orderByDesc('posting_date')->orderByDesc('id')->limit(200)->get();

        return Inertia::render('Inventory/ImportCosts/Index', [
            'documents' => $documents->map(fn (ImportCostDocument $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'posting_date' => $d->posting_date->format('Y-m-d'),
                'due_date' => $d->due_date?->format('Y-m-d'),
                'supplier' => $d->businessPartner?->code.' — '.$d->businessPartner?->name,
                'concept' => $d->concept,
                'concept_label' => ImportCostDocument::CONCEPTS[$d->concept] ?? $d->concept,
                'amount' => (float) $d->amount,
                'allocated_amount' => (float) $d->allocated_amount,
                'pending_amount' => (float) $d->pendingAmount(),
                'status' => $d->status,
                'status_label' => ImportCostDocument::STATUSES[$d->status],
                'journal_document_number' => $d->journalEntry?->document_number,
                // La vista inversa del mapa: desde el rubro, a qué
                // importaciones se cargó.
                'allocations' => $d->allocations->map(fn ($a) => [
                    'amount' => (float) $a->amount,
                    'receipt_id' => $a->landedCost?->inventory_document_id,
                    'posting_date' => $a->landedCost?->posting_date?->format('Y-m-d'),
                ])->values(),
            ])->values(),
            'concepts' => ImportCostDocument::CONCEPTS,
            'statuses' => ImportCostDocument::STATUSES,
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'documentTypes' => DocumentType::where('origin_module', 'compras')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Proceso de costeo: los rubros con saldo por asignar de un lado, las
     * importaciones recibidas del otro.
     */
    public function allocation(): Response
    {
        $pending = ImportCostDocument::pendingAllocation()
            ->with('businessPartner:id,code,name')
            ->orderBy('posting_date')
            ->get();

        // Solo importaciones: un rubro de nacionalización no se le carga a una
        // compra local. El servicio lo rechaza igual, pero la pantalla no
        // debería siquiera ofrecerlo.
        $receipts = InventoryDocument::imports()
            ->with(['documentType:id,code', 'businessPartner:id,code,name'])
            ->with(['lines.stockJournals:id,inventory_document_line_id,total_cost_local,direction'])
            ->orderByDesc('posting_date')
            ->limit(100)
            ->get();

        return Inertia::render('Inventory/ImportCosts/Allocate', [
            'pending' => $pending->map(fn (ImportCostDocument $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'posting_date' => $d->posting_date->format('Y-m-d'),
                'supplier' => $d->businessPartner?->code.' — '.$d->businessPartner?->name,
                'concept_label' => ImportCostDocument::CONCEPTS[$d->concept] ?? $d->concept,
                'amount' => (float) $d->amount,
                'pending_amount' => (float) $d->pendingAmount(),
            ])->values(),
            'receipts' => $receipts->map(fn (InventoryDocument $d) => [
                'id' => $d->id,
                'posting_date' => $d->posting_date->format('Y-m-d'),
                'document_type_code' => $d->documentType?->code,
                'supplier' => $d->businessPartner ? $d->businessPartner->code.' — '.$d->businessPartner->name : 'Sin proveedor',
                'customs_declaration' => $d->customs_declaration,
                'customs_office' => $d->customsOfficeLabel(),
                'total_local' => $d->lines->flatMap->stockJournals
                    ->where('direction', 'in')
                    ->sum(fn ($m) => (float) $m->total_cost_local),
            ])->values(),
            'documentTypes' => DocumentType::where('origin_module', 'compras')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')
                    ->where('company_id', $companyId)->where('origin_module', 'compras'),
            ],
            'business_partner_id' => [
                'required',
                Rule::exists('business_partners', 'id')->where('company_id', $companyId),
            ],
            'concept' => ['required', Rule::in(array_keys(ImportCostDocument::CONCEPTS))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $document = $this->importCostService->accrue(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                businessPartnerId: (int) $validated['business_partner_id'],
                concept: $validated['concept'],
                amount: $validated['amount'],
                documentDate: new \DateTimeImmutable($validated['document_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                dueDate: isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['import_cost' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('import-costs.index')
            ->with('success', "Rubro {$document->number} registrado; queda por asignar a una importación.");
    }

    /**
     * Asigna uno o varios rubros a una importación: es el momento en que el
     * costo entra al artículo y la transitoria se liquida.
     */
    public function allocate(Request $request, CurrentCompany $currentCompany): RedirectResponse
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
                    ->where('company_id', $companyId)->where('origin_module', 'compras'),
            ],
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'accruals' => ['required', 'array', 'min:1'],
            'accruals.*' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $this->landedCostService->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                receipt: InventoryDocument::findOrFail($validated['inventory_document_id']),
                businessPartnerId: null,
                amount: 0,
                documentDate: new \DateTimeImmutable($validated['posting_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
                accruals: $validated['accruals'],
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['import_cost' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory-movements.show', $validated['inventory_document_id'])
            ->with('success', 'Costos asignados: entraron al costo del artículo y la transitoria quedó liquidada.');
    }

    public function cancel(Request $request, int $importCost, CurrentCompany $currentCompany): RedirectResponse
    {
        $document = ImportCostDocument::findOrFail($importCost);

        try {
            $this->importCostService->cancel(
                Company::findOrFail($currentCompany->id()), $document, new \DateTimeImmutable
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['import_cost' => $e->getMessage()]);
        }

        return redirect()
            ->route('import-costs.index')
            ->with('success', 'Rubro cancelado; su asiento quedó revertido.');
    }
}
