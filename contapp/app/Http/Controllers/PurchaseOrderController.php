<?php

namespace App\Http\Controllers;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseOrderException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(PurchaseOrder::STATUSES))],
        ]);

        $orders = PurchaseOrder::with(['businessPartner:id,code,name'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'order_date' => $o->order_date->format('Y-m-d'),
                'expected_date' => $o->expected_date?->format('Y-m-d'),
                'supplier' => $o->businessPartner?->code.' — '.$o->businessPartner?->name,
                'status' => $o->status,
                'status_label' => PurchaseOrder::STATUSES[$o->status] ?? $o->status,
                'lines_count' => $o->lines_count,
                'description' => $o->description,
            ]);

        return Inertia::render('Inventory/PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => ['status' => $filters['status'] ?? null],
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/PurchaseOrders/Create', [
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->where('is_purchase_item', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'avg_cost_local']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, PurchaseOrderService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'business_partner_id' => ['required', Rule::exists('business_partners', 'id')->where('company_id', $companyId)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost_local' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $order = $service->place(
                Company::findOrFail($companyId),
                (int) $validated['business_partner_id'],
                new \DateTimeImmutable($validated['order_date']),
                array_map(fn (array $line) => new PurchaseOrderLineInput(
                    itemId: (int) $line['item_id'],
                    warehouseId: (int) $line['warehouse_id'],
                    quantity: $line['quantity'],
                    unitCostLocal: $line['unit_cost_local'] ?? 0,
                    description: $line['description'] ?? null,
                ), $validated['lines']),
                isset($validated['expected_date']) ? new \DateTimeImmutable($validated['expected_date']) : null,
                $validated['description'] ?? null,
                $request->user()?->id,
            );
        } catch (InvalidPurchaseOrderException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('purchase-orders.show', $order->id)
            ->with('success', "Orden de compra {$order->number} creada.");
    }

    public function show(int $purchaseOrder): Response
    {
        $order = PurchaseOrder::with([
            'lines.item:id,code,name',
            'lines.warehouse:id,code',
            'businessPartner:id,code,name',
            'receipts:id,purchase_order_id,posting_date,journal_entry_id,status',
        ])->findOrFail($purchaseOrder);

        return Inertia::render('Inventory/PurchaseOrders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'order_date' => $order->order_date->format('Y-m-d'),
                'expected_date' => $order->expected_date?->format('Y-m-d'),
                'supplier' => $order->businessPartner?->code.' — '.$order->businessPartner?->name,
                'status' => $order->status,
                'status_label' => PurchaseOrder::STATUSES[$order->status] ?? $order->status,
                'description' => $order->description,
                'is_pending' => in_array($order->status, PurchaseOrder::PENDING_STATUSES, true),
                'has_receipts' => $order->receipts->isNotEmpty(),
            ],
            'lines' => $order->lines->map(fn ($line) => [
                'line_number' => $line->line_number,
                'item_code' => $line->item?->code,
                'item_name' => $line->item?->name,
                'warehouse_code' => $line->warehouse?->code,
                'quantity' => (float) $line->quantity,
                'quantity_received' => (float) $line->quantity_received,
                'pending' => (float) $line->pending(),
                'unit_cost_local' => (float) $line->unit_cost_local,
            ]),
            'receipts' => $order->receipts->map(fn ($r) => [
                'id' => $r->id,
                'posting_date' => $r->posting_date->format('Y-m-d'),
                'status' => $r->status,
            ]),
        ]);
    }

    public function cancel(int $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        return $this->release($purchaseOrder, fn (PurchaseOrder $o) => $service->cancel($o), 'cancelada');
    }

    public function close(int $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        return $this->release($purchaseOrder, fn (PurchaseOrder $o) => $service->close($o), 'cerrada');
    }

    private function release(int $purchaseOrder, callable $action, string $verb): RedirectResponse
    {
        $order = PurchaseOrder::findOrFail($purchaseOrder);

        try {
            $action($order);
        } catch (InvalidPurchaseOrderException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('success', "Orden de compra {$order->number} {$verb}.");
    }
}
