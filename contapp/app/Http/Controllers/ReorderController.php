<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseOrderException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PurchaseOrderService;
use App\Domains\Inventory\Services\ReorderSuggestionExporter;
use App\Domains\Inventory\Services\ReorderSuggestionService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sugerencia de compra y configuración de niveles de reorden.
 *
 * Vive en el módulo de inventario y no en reportería: no es una consulta
 * contable sino el arranque de una acción — de acá sale la orden de compra.
 */
class ReorderController extends Controller
{
    private const TITLE = 'Sugerencia de compra';

    public function index(Request $request, CurrentCompany $currentCompany, ReorderSuggestionService $service): Response
    {
        $filters = $this->validateFilters($request);
        $suggestions = $this->build($service, $filters);

        return Inertia::render('Inventory/Reorder/Index', [
            'suggestions' => $suggestions,
            'filters' => $filters,
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'itemGroups' => ItemGroup::orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'estimatedTotal' => number_format(
                $suggestions->sum(fn (array $s) => (float) $s['estimated_cost']),
                2, '.', ''
            ),
        ]);
    }

    /**
     * La sugerencia se exporta porque casi nunca se compra desde la pantalla:
     * el archivo se manda a cotizar, se discute con el proveedor o se lleva a
     * autorizar, y recién después vuelve como orden. Sin exportar, ese
     * recorrido se hacía copiando la tabla a mano.
     */
    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        ReorderSuggestionService $service,
        ReorderSuggestionExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $filters = $this->validateFilters($request);
        $suggestions = $this->build($service, $filters);

        $header = $headerFactory->make(
            Company::findOrFail($currentCompany->id()),
            $request->user(),
            self::TITLE,
            $this->paramsSummary($filters),
        );

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $suggestions),
            'sugerencia-de-compra.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        ReorderSuggestionService $service,
        ReportHeaderFactory $headerFactory,
    ): HttpResponse {
        $filters = $this->validateFilters($request);
        $suggestions = $this->build($service, $filters);

        $header = $headerFactory->make(
            Company::findOrFail($currentCompany->id()),
            $request->user(),
            self::TITLE,
            $this->paramsSummary($filters),
        );

        $estimatedTotal = number_format(
            $suggestions->sum(fn (array $s) => (float) $s['estimated_cost']),
            2, '.', ''
        );

        return Pdf::loadView('reports.reorder-suggestion', compact('header', 'suggestions', 'estimatedTotal'))
            ->setPaper('letter', 'landscape')
            ->download('sugerencia-de-compra.pdf');
    }

    /**
     * Convierte las sugerencias elegidas en una orden de compra. No reimplanta
     * nada: arma las líneas y llama al mismo service que usa el formulario
     * manual, así una orden nacida de una sugerencia es indistinguible de una
     * digitada y pasa por las mismas validaciones.
     */
    public function order(Request $request, CurrentCompany $currentCompany, PurchaseOrderService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'business_partner_id' => ['required', Rule::exists('business_partners', 'id')->where('company_id', $companyId)],
            'expected_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $order = $service->place(
                Company::findOrFail($companyId),
                (int) $validated['business_partner_id'],
                new \DateTimeImmutable(now()->format('Y-m-d')),
                array_map(fn (array $line) => new PurchaseOrderLineInput(
                    itemId: (int) $line['item_id'],
                    warehouseId: (int) $line['warehouse_id'],
                    quantity: $line['quantity'],
                    unitCostLocal: Item::find($line['item_id'])?->avg_cost_local ?? 0,
                ), $validated['lines']),
                isset($validated['expected_date']) ? new \DateTimeImmutable($validated['expected_date']) : null,
                'Generada desde la sugerencia de compra',
                $request->user()?->id,
            );
        } catch (InvalidPurchaseOrderException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchase-orders.show', $order->id)
            ->with('success', "Orden de compra {$order->number} creada desde la sugerencia.");
    }

    /**
     * Los tres endpoints —pantalla, XLSX y PDF— comparten filtros y consulta:
     * la exportación tiene que decir exactamente lo mismo que se está viendo.
     *
     * @return array{warehouse_id: ?int, item_group_id: ?int}
     */
    private function validateFilters(Request $request): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'item_group_id' => ['nullable', 'integer', Rule::exists('item_groups', 'id')->where('company_id', $companyId)],
        ]);

        return [
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            'item_group_id' => isset($validated['item_group_id']) ? (int) $validated['item_group_id'] : null,
        ];
    }

    /**
     * @param  array{warehouse_id: ?int, item_group_id: ?int}  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function build(ReorderSuggestionService $service, array $filters)
    {
        return $service->build(
            Company::findOrFail(app(CurrentCompany::class)->id()),
            $filters['warehouse_id'],
            $filters['item_group_id'],
        );
    }

    private function paramsSummary(array $filters): string
    {
        $parts = [];

        if ($filters['warehouse_id'] !== null) {
            $parts[] = 'Almacén: '.(Warehouse::find($filters['warehouse_id'])?->code ?? $filters['warehouse_id']);
        }

        if ($filters['item_group_id'] !== null) {
            $parts[] = 'Grupo: '.(ItemGroup::find($filters['item_group_id'])?->code ?? $filters['item_group_id']);
        }

        // Sin filtros la lista es todo el catálogo bajo mínimo, y decirlo
        // explícito evita leer el archivo creyendo que está acotado.
        return $parts === [] ? 'Todos los almacenes y grupos' : implode(' · ', $parts);
    }

    /**
     * Niveles de reorden de un artículo, almacén por almacén. Se administran
     * dentro del artículo igual que sus lotes: fuera de él no significan nada.
     */
    public function levels(int $item): Response
    {
        $item = Item::findOrFail($item);

        $warehouses = Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']);

        $levels = ItemWarehouse::where('item_id', $item->id)
            ->get()
            ->keyBy('warehouse_id');

        return Inertia::render('Inventory/Items/ReorderLevels', [
            'item' => [
                ...$item->only(['id', 'code', 'name', 'is_inventory_item']),
                // Los niveles de la ficha: lo que aplica en cada almacén que
                // no defina el suyo.
                'default_minimum' => (float) $item->minimum_stock,
                'default_maximum' => $item->maximum_stock !== null ? (float) $item->maximum_stock : null,
            ],
            'rows' => $warehouses->map(fn (Warehouse $w) => [
                'warehouse_id' => $w->id,
                'warehouse_code' => $w->code,
                'warehouse_name' => $w->name,
                'on_hand' => (float) ($levels[$w->id]->on_hand ?? 0),
                'reserved' => (float) ($levels[$w->id]->reserved ?? 0),
                'ordered' => (float) ($levels[$w->id]->ordered ?? 0),
                // null = hereda de la ficha. Es distinto de 0, que significa
                // "este almacén NO lleva control de reorden" aunque el
                // artículo sí — el caso de una bodega de tránsito.
                'minimum_stock' => $levels[$w->id]->minimum_stock !== null
                    ? (float) $levels[$w->id]->minimum_stock
                    : null,
                'maximum_stock' => $levels[$w->id]->maximum_stock !== null
                    ? (float) $levels[$w->id]->maximum_stock
                    : null,
                'effective_minimum' => (float) ($levels[$w->id]->minimum_stock ?? $item->minimum_stock),
            ]),
        ]);
    }

    public function updateLevels(Request $request, int $item): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'levels' => ['required', 'array'],
            'levels.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            // Nullable: dejar el campo vacío es "heredar el de la ficha", que
            // es distinto de poner 0 ("este almacén no lleva reorden").
            'levels.*.minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'levels.*.maximum_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($validated['levels'] as $level) {
            $minimum = $level['minimum_stock'] ?? null;
            $maximum = $level['maximum_stock'] ?? null;

            // Se compara contra el mínimo que de verdad va a regir: si este
            // almacén hereda, el tope lo pone la ficha.
            $effectiveMinimum = (string) ($minimum ?? $item->minimum_stock);

            // Un máximo por debajo del mínimo dejaría la sugerencia en cero y
            // el artículo nunca se repondría, sin que nada lo avisara.
            if ($maximum !== null && bccomp((string) $maximum, $effectiveMinimum, 6) < 0) {
                return back()->withErrors([
                    'levels' => 'El máximo no puede ser menor que el mínimo: la sugerencia quedaría en cero y el artículo no se repondría nunca.',
                ]);
            }

            ItemWarehouse::updateOrCreate(
                ['item_id' => $item->id, 'warehouse_id' => (int) $level['warehouse_id']],
                ['minimum_stock' => $minimum, 'maximum_stock' => $maximum],
            );
        }

        return back()->with('success', "Niveles de reorden de {$item->code} actualizados.");
    }
}
