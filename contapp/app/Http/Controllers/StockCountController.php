<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\StockCount;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\StockCountService;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Toma física de inventario: abrir con sus parámetros, imprimir la hoja de
 * conteo, capturar lo contado y cerrar generando el ajuste.
 */
class StockCountController extends Controller
{
    public function __construct(private readonly StockCountService $service) {}

    public function index(): Response
    {
        $counts = StockCount::with(['warehouse:id,code,name', 'itemGroup:id,code,name'])
            ->withCount('lines')
            ->orderByDesc('cutoff_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Inventory/StockCounts/Index', [
            'counts' => $counts->map(fn (StockCount $count) => [
                'id' => $count->id,
                'number' => $count->number,
                'cutoff_date' => $count->cutoff_date->format('Y-m-d'),
                'warehouse' => $count->warehouse?->code.' — '.$count->warehouse?->name,
                'item_group' => $count->itemGroup?->name,
                'blind' => $count->blind,
                'status' => $count->status,
                'status_label' => StockCount::STATUSES[$count->status],
                'lines_count' => $count->lines_count,
            ])->values(),
            'statuses' => StockCount::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/StockCounts/Create', [
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'itemGroups' => ItemGroup::orderBy('code')->get(['id', 'code', 'name']),
            'documentTypes' => DocumentType::where('origin_module', 'inventario')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            // Almacenes con una toma abierta: no admiten otra hasta cerrarla.
            'busyWarehouses' => StockCount::where('status', 'open')->pluck('warehouse_id'),
        ]);
    }

    public function show(int $stockCount): Response
    {
        $count = $this->findWithLines($stockCount);

        return Inertia::render('Inventory/StockCounts/Show', [
            'count' => $this->header($count),
            'lines' => $this->lines($count),
        ]);
    }

    /**
     * Hoja de conteo en PDF. En un conteo a ciegas la columna de existencia
     * teórica sale vacía: quien cuenta no debe ver el número que "tiene que
     * dar", porque es lo que sesga el conteo.
     */
    public function print(Request $request, int $stockCount, ReportHeaderFactory $headerFactory): \Illuminate\Http\Response
    {
        $count = $this->findWithLines($stockCount);

        $params = "Corte al {$count->cutoff_date->format('Y-m-d')} · Almacén {$count->warehouse?->code}"
            .($count->itemGroup ? " · Familia {$count->itemGroup->name}" : ' · Todas las familias')
            .($count->blind ? ' · Conteo a ciegas' : '');

        $header = $headerFactory->make(
            Company::findOrFail($count->company_id),
            $request->user(),
            "Hoja de toma física #{$count->number}",
            $params,
        );

        return Pdf::loadView('inventory.stock-count', ['header' => $header, 'count' => $count])
            ->setPaper('letter')
            ->download("toma-fisica-{$count->number}.pdf");
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')
                    ->where('company_id', $companyId)->where('origin_module', 'inventario'),
            ],
            'cutoff_date' => ['required', 'date'],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'item_group_id' => ['nullable', Rule::exists('item_groups', 'id')->where('company_id', $companyId)],
            'blind' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $count = $this->service->open(
                company: Company::findOrFail($companyId),
                documentType: DocumentType::findOrFail($validated['document_type_id']),
                cutoffDate: new \DateTimeImmutable($validated['cutoff_date']),
                warehouseId: (int) $validated['warehouse_id'],
                itemGroupId: isset($validated['item_group_id']) ? (int) $validated['item_group_id'] : null,
                blind: $validated['blind'] ?? false,
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('stock-counts.show', $count->id)
            ->with('success', "Toma física {$count->number} abierta con {$count->lines->count()} línea(s) por contar.");
    }

    public function capture(Request $request, int $stockCount, CurrentCompany $currentCompany): RedirectResponse
    {
        $count = StockCount::findOrFail($stockCount);

        $validated = $request->validate([
            'counted' => ['required', 'array'],
            'counted.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->service->capture(
                Company::findOrFail($currentCompany->id()), $count, $validated['counted']
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return back()->with('success', 'Conteo guardado.');
    }

    public function post(Request $request, int $stockCount, CurrentCompany $currentCompany): RedirectResponse
    {
        $count = StockCount::findOrFail($stockCount);

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
        ]);

        try {
            $closed = $this->service->post(
                company: Company::findOrFail($currentCompany->id()),
                count: $count,
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                postedBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect()
            ->route('stock-counts.show', $count->id)
            ->with('success', $closed->inventory_document_id
                ? 'Toma física cerrada; el ajuste quedó contabilizado.'
                : 'Toma física cerrada: el conteo cuadró y no hubo nada que ajustar.');
    }

    public function cancel(Request $request, int $stockCount, CurrentCompany $currentCompany): RedirectResponse
    {
        $count = StockCount::findOrFail($stockCount);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->service->cancel(
                Company::findOrFail($currentCompany->id()), $count, $validated['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect()
            ->route('stock-counts.show', $count->id)
            ->with('success', 'Toma física cancelada; el almacén queda libre para abrir otra.');
    }

    private function findWithLines(int $id): StockCount
    {
        return StockCount::with([
            'warehouse:id,code,name', 'itemGroup:id,code,name', 'documentType:id,code',
            'lines.item:id,code,name', 'lines.warehouseBin:id,code',
            'inventoryDocument:id,journal_entry_id',
        ])->findOrFail($id);
    }

    private function header(StockCount $count): array
    {
        return [
            'id' => $count->id,
            'number' => $count->number,
            'cutoff_date' => $count->cutoff_date->format('Y-m-d'),
            'warehouse' => $count->warehouse?->code.' — '.$count->warehouse?->name,
            'item_group' => $count->itemGroup?->name,
            'blind' => $count->blind,
            'status' => $count->status,
            'status_label' => StockCount::STATUSES[$count->status],
            'description' => $count->description,
            'posted_at' => $count->posted_at?->format('Y-m-d H:i'),
            'inventory_document_id' => $count->inventory_document_id,
            'journal_entry_id' => $count->inventoryDocument?->journal_entry_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(StockCount $count): array
    {
        return $count->lines->map(fn ($line) => [
            'id' => $line->id,
            'line_number' => $line->line_number,
            'item' => $line->item?->code.' — '.$line->item?->name,
            'bin_code' => $line->warehouseBin?->code,
            'theoretical_quantity' => (float) $line->theoretical_quantity,
            'counted_quantity' => $line->counted_quantity === null ? null : (float) $line->counted_quantity,
            'unit_cost_local' => (float) $line->unit_cost_local,
            'difference' => $line->isCounted() ? (float) $line->difference() : null,
            'difference_value' => $line->isCounted() ? (float) $line->differenceValue() : null,
        ])->values()->all();
    }
}
