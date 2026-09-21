<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\ImportDetailsInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PurchaseCycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryDocumentController extends Controller
{
    public function __construct(private readonly PostStockMovementService $postStockMovementService) {}

    /**
     * Antes traía los últimos 200 documentos con un limit() duro. Eso no es
     * una cota de rendimiento sino un agujero: a partir del documento 201 la
     * información deja de existir para la pantalla, sin que nada lo avise.
     * Paginado y con filtros, todo sigue alcanzable.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'operation' => ['nullable', 'string', Rule::in(array_keys(InventoryDocument::OPERATIONS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $documents = InventoryDocument::with(['documentType:id,code', 'journalEntry:id,document_number'])
            ->withCount('lines')
            ->when($filters['operation'] ?? null, fn ($q, $op) => $q->where('operation', $op))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('posting_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('posting_date', '<=', $to))
            ->orderByDesc('posting_date')
            ->orderByDesc('id')
            ->select(['id', 'document_type_id', 'journal_entry_id', 'operation', 'posting_date', 'description', 'status'])
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Movements/Index', [
            'documents' => $documents,
            'filters' => [
                'operation' => $filters['operation'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'operations' => InventoryDocument::OPERATIONS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Movements/Create', [
            'operations' => array_intersect_key(
                InventoryDocument::OPERATIONS,
                array_flip(InventoryDocument::MANUAL_OPERATIONS),
            ),
            'documentTypes' => DocumentType::where('origin_module', 'inventario')
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'avg_cost_local', 'tracks_lots']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'uses_bins']),
            'bins' => WarehouseBin::whereIn('warehouse_id', Warehouse::pluck('id'))
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'warehouse_id', 'code']),
            'suppliers' => BusinessPartner::whereIn('type', ['supplier', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'customsOffices' => InventoryDocument::CUSTOMS_OFFICES,
        ]);
    }

    public function show(int $inventoryDocument): Response
    {
        $document = InventoryDocument::with([
            'documentType:id,code,name',
            'journalEntry:id,document_number,posting_date',
            'invoiceJournalEntry:id,document_number,posting_date',
            'sourceDocument:id,posting_date,operation',
            'businessPartner:id,code,name',
            'lines.item:id,code,name',
            'lines.warehouse:id,code,name',
            'lines.stockJournals',
        ])->findOrFail($inventoryDocument);

        return Inertia::render('Inventory/Movements/Show', [
            'document' => $document,
            'operationLabel' => InventoryDocument::OPERATIONS[$document->operation],
            // Mapa del ciclo de compra: null en los movimientos que no son
            // parte de uno (una salida, un conteo, un traslado).
            'cycle' => app(PurchaseCycleService::class)->map($document),
            'voidable' => $document->isVoidable(),
            'customsOffices' => InventoryDocument::CUSTOMS_OFFICES,
        ]);
    }

    /**
     * Anula una entrada por compra todavía no facturada. El servicio revierte
     * inventario y contabilidad en la misma transacción; acá solo se traduce
     * el resultado a la pantalla.
     */
    public function void(Request $request, int $inventoryDocument, CurrentCompany $currentCompany): RedirectResponse
    {
        $document = InventoryDocument::findOrFail($inventoryDocument);

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $reversal = $this->postStockMovementService->void(
                company: Company::findOrFail($currentCompany->id()),
                document: $document,
                postingDate: new \DateTimeImmutable($validated['posting_date']),
                description: $validated['description'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory-movements.show', $reversal->id)
            ->with('success', 'Entrada anulada: la mercancía salió al costo con que entró y la cuenta puente quedó en cero.');
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            // Solo las operaciones de captura manual: una devolución o una
            // salida por venta nacen de su documento de origen y deben pasar
            // por su servicio para quedar enlazadas.
            'operation' => ['required', Rule::in(InventoryDocument::MANUAL_OPERATIONS)],
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
            // Datos de importación: solo tienen sentido en una entrada por
            // compra, y el servicio lo vuelve a verificar.
            'is_import' => ['boolean'],
            'customs_declaration' => ['nullable', 'string', 'max:40'],
            'customs_office' => ['nullable', Rule::in(array_keys(InventoryDocument::CUSTOMS_OFFICES))],
            'transport_document' => ['nullable', 'string', 'max:60'],
            'origin_country' => ['nullable', 'string', 'max:60'],
            'customs_date' => ['nullable', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            // La pertenencia al almacén y la exigencia según uses_bins las
            // valida PostStockMovementService, que es quien conoce la regla.
            'lines.*.warehouse_bin_id' => ['nullable', 'integer'],
            // Igual que la ubicación: la pertenencia al artículo, la
            // exigencia según tracks_lots y el vencimiento los valida
            // ItemLotResolver, que es quien conoce la regla.
            'lines.*.item_lot_id' => ['nullable', 'integer'],
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
            itemLotId: isset($line['item_lot_id']) ? (int) $line['item_lot_id'] : null,
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
                import: ($validated['is_import'] ?? false) ? new ImportDetailsInput(
                    customsDeclaration: $validated['customs_declaration'] ?? null,
                    customsOffice: $validated['customs_office'] ?? null,
                    transportDocument: $validated['transport_document'] ?? null,
                    originCountry: $validated['origin_country'] ?? null,
                    customsDate: isset($validated['customs_date'])
                        ? new \DateTimeImmutable($validated['customs_date']) : null,
                ) : null,
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

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        // Sin rango, este kardex traía TODO el histórico del artículo sin
        // paginar: con dos o tres años de movimientos deja de ser usable y
        // carga la tabla que más crece del módulo. Por defecto arranca en el
        // año fiscal vigente, que además es el corte natural para un
        // contador. Vaciar el campo sigue trayendo todo — es una decisión
        // explícita de quien consulta, no el comportamiento por omisión.
        $from = $filters['from'] ?? $this->defaultKardexFrom();
        $to = $filters['to'] ?? null;

        // Con rango, los movimientos anteriores no desaparecen: se resumen en
        // una línea de saldo inicial. Un kardex filtrado sin saldo inicial
        // mostraría un saldo corriente que no cuadra con nada.
        $opening = $from !== null
            ? $this->kardexOpening($item->id, $warehouseId, $from)
            : null;

        $movements = StockJournal::with([
            'warehouse:id,code,name',
            'journalEntry:id,document_number',
            'documentLine:id,inventory_document_id',
        ])
            ->where('item_id', $item->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($from, fn ($q) => $q->whereDate('posting_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('posting_date', '<=', $to))
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Inventory/Kardex/Show', [
            'item' => $item->only(['id', 'code', 'name', 'avg_cost_local', 'avg_cost_foreign']),
            'uomCode' => $item->unitOfMeasure?->code,
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'selectedWarehouseId' => $warehouseId,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
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

    /**
     * Primer día del año fiscal vigente, o hace un año si la compañía todavía
     * no tiene ninguno abierto.
     */
    private function defaultKardexFrom(): string
    {
        $year = FiscalYear::where('status', 'open')->max('year');

        return $year !== null
            ? sprintf('%d-01-01', $year)
            : now()->subYear()->format('Y-m-d');
    }

    /**
     * Saldo inicial del kardex: cantidad y valor acumulados ANTES del corte.
     * Se deriva de los movimientos —igual que el reporte de existencias
     * valorizadas— y no de `balance_quantity`/`avg_cost_local_after`, que son
     * foto de auditoría y no fuente de verdad para una consulta
     * (docs/decisiones.md, Fase 0).
     *
     * @return array{quantity: string, value_local: string, unit_cost_local: string}
     */
    private function kardexOpening(int $itemId, ?int $warehouseId, string $from): array
    {
        $signedQty = "CASE WHEN direction = 'in' THEN quantity ELSE -quantity END";
        $signedValue = "CASE WHEN direction = 'in' THEN total_cost_local ELSE -total_cost_local END";

        $row = StockJournal::where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereDate('posting_date', '<', $from)
            ->selectRaw("SUM({$signedQty}) as quantity, SUM({$signedValue}) as value_local")
            ->first();

        $quantity = number_format((float) ($row->quantity ?? 0), 6, '.', '');
        $value = number_format((float) ($row->value_local ?? 0), 2, '.', '');

        return [
            'quantity' => $quantity,
            'value_local' => $value,
            'unit_cost_local' => bccomp($quantity, '0.000000', 6) === 0
                ? '0.000000'
                : bcdiv($value, $quantity, 6),
        ];
    }
}
