<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostStockMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryDocumentController extends Controller
{
    public function __construct(private readonly PostStockMovementService $postStockMovementService) {}

    public function index(): Response
    {
        return Inertia::render('Inventory/Movements/Index', [
            'documents' => InventoryDocument::with(['documentType:id,code', 'journalEntry:id,document_number'])
                ->withCount('lines')
                ->orderByDesc('posting_date')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'document_type_id', 'journal_entry_id', 'operation', 'posting_date', 'description', 'status']),
            'operations' => InventoryDocument::OPERATIONS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Movements/Create', [
            'operations' => InventoryDocument::OPERATIONS,
            'documentTypes' => DocumentType::where('origin_module', 'inventario')
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'avg_cost_local']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'uses_bins']),
            'bins' => WarehouseBin::whereIn('warehouse_id', Warehouse::pluck('id'))
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code']),
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function show(int $inventoryDocument): Response
    {
        $document = InventoryDocument::with([
            'documentType:id,code,name',
            'journalEntry:id,document_number,posting_date',
            'invoiceJournalEntry:id,document_number,posting_date',
            'businessPartner:id,code,name',
            'lines.item:id,code,name',
            'lines.warehouse:id,code,name',
            'lines.stockJournals',
        ])->findOrFail($inventoryDocument);

        return Inertia::render('Inventory/Movements/Show', [
            'document' => $document,
            'operationLabel' => InventoryDocument::OPERATIONS[$document->operation],
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'operation' => ['required', Rule::in(array_keys(InventoryDocument::OPERATIONS))],
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')
                    ->where('company_id', $companyId)
                    ->where('origin_module', 'inventario'),
            ],
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'business_partner_id' => [
                // Solo una entrada por compra lleva proveedor: es lo que crea
                // la cuenta puente que la factura liquidará después.
                Rule::requiredIf(fn () => $request->input('operation') === 'purchase_receipt'),
                'nullable',
                Rule::exists('business_partners', 'id')
                    ->where('company_id', $companyId)
                    ->whereIn('type', ['supplier', 'both']),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            // La pertenencia al almacén y la exigencia según uses_bins las
            // valida PostStockMovementService, que es quien conoce la regla.
            'lines.*.warehouse_bin_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost_local' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $line) => new StockLineInput(
            itemId: (int) $line['item_id'],
            warehouseId: (int) $line['warehouse_id'],
            quantity: $line['quantity'],
            unitCostLocal: $line['unit_cost_local'] ?? null,
            description: $line['description'] ?? null,
            warehouseBinId: isset($line['warehouse_bin_id']) ? (int) $line['warehouse_bin_id'] : null,
        ), $validated['lines']);

        try {
            $document = $this->postStockMovementService->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                operation: $validated['operation'],
                documentDate: new \DateTimeImmutable($validated['document_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                lines: $lines,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
                businessPartnerId: $validated['business_partner_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            // Toda excepción de dominio (existencia insuficiente, cuenta sin
            // configurar, período cerrado, cuenta que no acepta movimientos)
            // ya revirtió la transacción completa: acá solo se traduce a un
            // mensaje de formulario.
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory-movements.show', $document->id)
            ->with('success', 'Movimiento de inventario contabilizado.');
    }

    /**
     * Kardex de un artículo: movimientos y saldo, por almacén o consolidado.
     * Solo lectura sobre stock_journals, que es append-only.
     */
    public function kardex(Request $request, int $item): Response
    {
        $item = Item::with('unitOfMeasure:id,code')->findOrFail($item);

        $warehouseId = $request->integer('warehouse_id') ?: null;

        $movements = StockJournal::with([
            'warehouse:id,code,name',
            'journalEntry:id,document_number',
            'documentLine:id,inventory_document_id',
        ])
            ->where('item_id', $item->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Inventory/Kardex/Show', [
            'item' => $item->only(['id', 'code', 'name', 'avg_cost_local', 'avg_cost_foreign']),
            'uomCode' => $item->unitOfMeasure?->code,
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'selectedWarehouseId' => $warehouseId,
            'movements' => $movements->map(fn (StockJournal $m) => [
                'id' => $m->id,
                'posting_date' => $m->posting_date->format('Y-m-d'),
                'warehouse_code' => $m->warehouse?->code,
                'direction' => $m->direction,
                'quantity' => $m->quantity,
                'unit_cost_local' => $m->unit_cost_local,
                'total_cost_local' => $m->total_cost_local,
                'total_cost_foreign' => $m->total_cost_foreign,
                'balance_quantity' => $m->balance_quantity,
                'avg_cost_local_after' => $m->avg_cost_local_after,
                'journal_document_number' => $m->journalEntry?->document_number,
                'inventory_document_id' => $m->documentLine?->inventory_document_id,
            ])->values(),
        ]);
    }
}
