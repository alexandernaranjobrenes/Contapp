<?php

namespace App\Http\Controllers;

use App\Domains\Billing\DataTransferObjects\SalesOrderLineInput;
use App\Domains\Billing\Exceptions\PriceOverrideNotAuthorizedException;
use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Services\PriceOverrideAuthorizer;
use App\Domains\Billing\Services\PriceOverrideGuard;
use App\Domains\Billing\Services\SalesOrderService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Órdenes de pedido: el compromiso con el cliente antes de la factura. No
 * tocan contabilidad; lo único que mueven es cuánta mercancía queda libre.
 */
class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $service,
        private readonly PriceOverrideGuard $priceOverrideGuard,
        private readonly PriceOverrideAuthorizer $priceOverrideAuthorizer,
    ) {}

    public function index(): Response
    {
        $orders = SalesOrder::with(['businessPartner:id,code,name', 'lines'])
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Billing/SalesOrders/Index', [
            'orders' => $orders->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'order_date' => $order->order_date->format('Y-m-d'),
                'delivery_date' => $order->delivery_date?->format('Y-m-d'),
                'customer' => $order->businessPartner?->code.' — '.$order->businessPartner?->name,
                'status' => $order->status,
                'status_label' => SalesOrder::STATUSES[$order->status],
                'lines_count' => $order->lines->count(),
                'pending' => (float) $order->lines->sum(fn ($line) => (float) $line->pending()),
            ])->values(),
            'statuses' => SalesOrder::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Billing/SalesOrders/Create', [
            'customers' => BusinessPartner::whereIn('type', ['client', 'both'])
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            // Disponible = existencia menos lo ya apartado. Es el número que
            // decide si el pedido se puede tomar, así que va a la pantalla.
            'stock' => ItemWarehouse::whereIn('item_id', Item::pluck('id'))
                ->get(['item_id', 'warehouse_id', 'on_hand', 'reserved'])
                ->map(fn (ItemWarehouse $row) => [
                    'item_id' => $row->item_id,
                    'warehouse_id' => $row->warehouse_id,
                    'on_hand' => (float) $row->on_hand,
                    'reserved' => (float) $row->reserved,
                    'free' => (float) bcsub((string) $row->on_hand, (string) $row->reserved, 6),
                ])->values(),
            // Si quien toma el pedido ya puede liberar cambios de precio, la
            // pantalla no le pide autorización a nadie. El servidor lo
            // vuelve a comprobar.
            'canAuthorizePriceChange' => $this->priceOverrideGuard->canAuthorize(
                request()->user(), app(CurrentCompany::class)->id()
            ),
        ]);
    }

    public function show(int $salesOrder): Response
    {
        $order = SalesOrder::with([
            'businessPartner:id,code,name',
            'lines.item:id,code,name',
            'lines.warehouse:id,code',
            'salesDocuments:id,sales_order_id,consecutive,posting_date,total_document',
        ])->findOrFail($salesOrder);

        return Inertia::render('Billing/SalesOrders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'order_date' => $order->order_date->format('Y-m-d'),
                'delivery_date' => $order->delivery_date?->format('Y-m-d'),
                'customer' => $order->businessPartner?->code.' — '.$order->businessPartner?->name,
                'business_partner_id' => $order->business_partner_id,
                'status' => $order->status,
                'status_label' => SalesOrder::STATUSES[$order->status],
                'description' => $order->description,
            ],
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'item' => $line->item?->code.' — '.$line->item?->name,
                'warehouse_code' => $line->warehouse?->code,
                'quantity' => (float) $line->quantity,
                'quantity_invoiced' => (float) $line->quantity_invoiced,
                'pending' => (float) $line->pending(),
                'unit_price' => (float) $line->unit_price,
            ])->values(),
            'invoices' => $order->salesDocuments->map(fn ($doc) => [
                'id' => $doc->id,
                'consecutive' => $doc->consecutive,
                'posting_date' => $doc->posting_date->format('Y-m-d'),
                'total' => (float) $doc->total_document,
            ])->values(),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'business_partner_id' => [
                'required',
                Rule::exists('business_partners', 'id')->where('company_id', $companyId),
            ],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $company = Company::findOrFail($companyId);

        // El precio se pacta al tomar el pedido, no al facturarlo: si el
        // control esperara a la factura, el vendedor ya habría comprometido
        // por escrito un precio que la empresa no autorizó. Lo que se firme
        // acá viaja a la factura que cumpla este pedido y no se vuelve a
        // pedir.
        try {
            $deviations = $this->resolvePriceOverrides($request, $company, $validated);
        } catch (PriceOverrideNotAuthorizedException $e) {
            return back()->withErrors(['price_override' => $e->getMessage()])->withInput();
        }

        $lines = array_map(fn (array $line) => new SalesOrderLineInput(
            itemId: (int) $line['item_id'],
            warehouseId: (int) $line['warehouse_id'],
            quantity: $line['quantity'],
            unitPrice: $line['unit_price'] ?? null,
            description: $line['description'] ?? null,
        ), $validated['lines']);

        try {
            $order = $this->service->place(
                company: Company::findOrFail($companyId),
                businessPartnerId: (int) $validated['business_partner_id'],
                orderDate: new \DateTimeImmutable($validated['order_date']),
                lines: $lines,
                deliveryDate: isset($validated['delivery_date'])
                    ? new \DateTimeImmutable($validated['delivery_date']) : null,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()])->withInput();
        }

        $this->recordPriceOverrides($order, $deviations);

        return redirect()
            ->route('sales-orders.show', $order->id)
            ->with('success', "Pedido {$order->number} registrado; la mercancía quedó apartada.");
    }

    /**
     * Misma regla que en la factura: el precio de la lista se respeta y
     * apartarse de él lo libera un administrador en el momento.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PriceOverrideNotAuthorizedException
     */
    private function resolvePriceOverrides(Request $request, Company $company, array $validated): array
    {
        $user = $request->user();

        $deviations = $this->priceOverrideGuard->deviations(
            $company,
            BusinessPartner::find($validated['business_partner_id']),
            $validated['order_date'],
            // El pedido no elige moneda: se toma en la de la compañía, así
            // que la lista que aplica es la de moneda local.
            $company->local_currency_id,
            $validated['lines'],
        );

        if ($deviations === [] || $this->priceOverrideGuard->canAuthorize($user, $company->id)) {
            return [];
        }

        $email = trim((string) $request->input('price_override_email'));
        $password = (string) $request->input('price_override_password');

        if ($email === '' || $password === '') {
            throw new PriceOverrideNotAuthorizedException(sprintf(
                'Este pedido se aparta de la lista de precios en %d línea(s). '.
                'Para registrarlo hace falta que lo autorice un administrador o el superusuario.',
                count($deviations),
            ));
        }

        $authorizer = $this->priceOverrideAuthorizer->verify($email, $password, $company->id, $user->id);

        $reason = $request->input('price_override_reason');

        return array_map(fn (array $d) => [
            ...$d,
            'requested_by' => $user->id,
            'authorized_by' => $authorizer->id,
            'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        ], $deviations);
    }

    /**
     * @param  array<int, array<string, mixed>>  $deviations
     */
    private function recordPriceOverrides(SalesOrder $order, array $deviations): void
    {
        if ($deviations === []) {
            return;
        }

        $lineIds = $order->lines()->orderBy('id')->pluck('id')->all();

        foreach ($deviations as $index => $deviation) {
            PriceOverrideAuthorization::create([
                'company_id' => $order->company_id,
                'sales_order_id' => $order->id,
                'sales_order_line_id' => $lineIds[$index] ?? null,
                'item_id' => $deviation['item_id'],
                'price_list_id' => $deviation['price_list_id'],
                'price_list_code' => $deviation['price_list_code'],
                'list_unit_price' => $deviation['list_unit_price'],
                'invoiced_unit_price' => $deviation['invoiced_unit_price'],
                'difference' => $deviation['difference'],
                'requested_by' => $deviation['requested_by'],
                'authorized_by' => $deviation['authorized_by'],
                'reason' => $deviation['reason'],
            ]);
        }
    }

    public function cancel(Request $request, int $salesOrder, CurrentCompany $currentCompany): RedirectResponse
    {
        $order = SalesOrder::findOrFail($salesOrder);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->cancel(
                Company::findOrFail($currentCompany->id()), $order, $validated['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales-orders.show', $order->id)
            ->with('success', 'Pedido cancelado; la mercancía apartada volvió a quedar disponible.');
    }
}
