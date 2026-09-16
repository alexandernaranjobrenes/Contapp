<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductionOrderController extends Controller
{
    public function __construct(private readonly PostProductionService $postProductionService) {}

    public function index(): Response
    {
        $orders = ProductionOrder::with(['item:id,code,name', 'warehouse:id,code'])
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Inventory/Production/Index', [
            'orders' => $orders->map(fn (ProductionOrder $o) => [
                'id' => $o->id,
                'order_date' => $o->order_date->format('Y-m-d'),
                'item' => $o->item?->code.' — '.$o->item?->name,
                'warehouse_code' => $o->warehouse?->code,
                'planned_quantity' => $o->planned_quantity,
                'produced_quantity' => $o->produced_quantity,
                'status' => $o->status,
                'description' => $o->description,
                'wip_balance' => $o->wipBalance(),
                'variance_journal_entry_id' => $o->variance_journal_entry_id,
            ])->values(),
            'documentTypes' => DocumentType::where('origin_module', 'inventario')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('status', 'active')->where('is_inventory_item', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'uses_bins']),
            'bins' => WarehouseBin::whereIn('warehouse_id', Warehouse::pluck('id'))
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'order_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        ProductionOrder::create([
            ...$validated,
            'company_id' => $companyId,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Orden de fabricación creada.');
    }

    public function issue(Request $request, int $productionOrder, CurrentCompany $currentCompany): RedirectResponse
    {
        $order = ProductionOrder::findOrFail($productionOrder);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => $this->documentTypeRule($companyId),
            'posting_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_bin_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $lines = array_map(fn (array $line) => new StockLineInput(
            itemId: (int) $line['item_id'],
            warehouseId: (int) $line['warehouse_id'],
            quantity: $line['quantity'],
            warehouseBinId: isset($line['warehouse_bin_id']) ? (int) $line['warehouse_bin_id'] : null,
        ), $validated['lines']);

        return $this->run(fn () => $this->postProductionService->issue(
            company: Company::findOrFail($companyId),
            documentType: DocumentType::findOrFail($validated['document_type_id']),
            order: $order,
            documentDate: new \DateTimeImmutable($validated['posting_date']),
            postingDate: new \DateTimeImmutable($validated['posting_date']),
            lines: $lines,
            createdBy: $request->user()->id,
        ), 'Materia prima emitida a la orden.');
    }

    public function receive(Request $request, int $productionOrder, CurrentCompany $currentCompany): RedirectResponse
    {
        $order = ProductionOrder::findOrFail($productionOrder);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => $this->documentTypeRule($companyId),
            'posting_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->run(fn () => $this->postProductionService->receive(
            company: Company::findOrFail($companyId),
            documentType: DocumentType::findOrFail($validated['document_type_id']),
            order: $order,
            quantity: $validated['quantity'],
            documentDate: new \DateTimeImmutable($validated['posting_date']),
            postingDate: new \DateTimeImmutable($validated['posting_date']),
            createdBy: $request->user()->id,
        ), 'Producto terminado ingresado; la cuenta en proceso quedó descargada.');
    }

    public function close(Request $request, int $productionOrder, CurrentCompany $currentCompany): RedirectResponse
    {
        $order = ProductionOrder::findOrFail($productionOrder);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => $this->documentTypeRule($companyId),
            'posting_date' => ['required', 'date'],
        ]);

        return $this->run(fn () => $this->postProductionService->close(
            company: Company::findOrFail($companyId),
            documentType: DocumentType::findOrFail($validated['document_type_id']),
            order: $order,
            postingDate: new \DateTimeImmutable($validated['posting_date']),
            createdBy: $request->user()->id,
        ), 'Orden cerrada.');
    }

    private function documentTypeRule(int $companyId): array
    {
        return [
            'required',
            Rule::exists('document_types', 'id')
                ->where('company_id', $companyId)
                ->where('origin_module', 'inventario'),
        ];
    }

    /**
     * Toda excepción de dominio ya revirtió su transacción completa: acá solo
     * se traduce a un mensaje de formulario.
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['production' => $e->getMessage()])->withInput();
        }

        return back()->with('success', $success);
    }
}
