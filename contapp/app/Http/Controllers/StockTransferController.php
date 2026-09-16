<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockTransferLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostStockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(private readonly PostStockTransferService $postStockTransferService) {}

    public function index(): Response
    {
        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => InventoryDocument::where('operation', 'transfer')
                ->with([
                    'documentType:id,code',
                    'journalEntry:id,document_number',
                    'lines.item:id,code,name',
                    'lines.warehouse:id,code',
                    'lines.toWarehouse:id,code',
                ])
                ->orderByDesc('posting_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
            'items' => Item::where('status', 'active')->where('is_inventory_item', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'uses_bins']),
            'bins' => WarehouseBin::whereIn('warehouse_id', Warehouse::pluck('id'))
                ->where('status', 'active')->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'documentTypes' => DocumentType::where('origin_module', 'inventario')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')->where('company_id', $companyId)->where('origin_module', 'inventario'),
            ],
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.from_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.to_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            // La pertenencia de la ubicación al almacén y la exigencia según
            // uses_bins las valida el service, que es quien conoce la regla.
            'lines.*.from_warehouse_bin_id' => ['nullable', 'integer'],
            'lines.*.to_warehouse_bin_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $line) => new StockTransferLineInput(
            itemId: (int) $line['item_id'],
            fromWarehouseId: (int) $line['from_warehouse_id'],
            toWarehouseId: (int) $line['to_warehouse_id'],
            quantity: $line['quantity'],
            fromWarehouseBinId: isset($line['from_warehouse_bin_id']) ? (int) $line['from_warehouse_bin_id'] : null,
            toWarehouseBinId: isset($line['to_warehouse_bin_id']) ? (int) $line['to_warehouse_bin_id'] : null,
            description: $line['description'] ?? null,
        ), $validated['lines']);

        try {
            $this->postStockTransferService->post(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                documentDate: new \DateTimeImmutable($validated['posting_date']),
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                lines: $lines,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Traslado registrado.');
    }
}
