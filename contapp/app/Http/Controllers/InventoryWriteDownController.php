<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Exceptions\InvalidWriteDownException;
use App\Domains\Inventory\Models\InventoryWriteDown;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Services\PostInventoryWriteDownService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avalúos de deterioro de inventario (NIC 2 §28). El proceso es periódico y
 * manual a propósito: el valor neto realizable es un juicio basado en "la
 * evidencia más fiable disponible" (§30), no una fórmula que el sistema
 * pueda calcular solo.
 */
class InventoryWriteDownController extends Controller
{
    public function index(): Response
    {
        $writeDowns = InventoryWriteDown::with(['journalEntry:id,document_number'])
            ->withCount('lines')
            ->withSum('lines as total_movement', 'movement_local')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InventoryWriteDown $w) => [
                'id' => $w->id,
                'as_of' => $w->as_of->format('Y-m-d'),
                'description' => $w->description,
                'lines_count' => $w->lines_count,
                'total_movement' => (float) ($w->total_movement ?? 0),
                'journal_document_number' => $w->journalEntry?->document_number,
            ]);

        return Inertia::render('Inventory/WriteDowns/Index', [
            'writeDowns' => $writeDowns,
        ]);
    }

    /**
     * Pantalla del avalúo: trae cada artículo con existencia al corte, su
     * costo y lo que ya tiene estimado, para que quien avalúa solo tenga que
     * digitar el VNR de los que está revisando.
     */
    public function create(Request $request, CurrentCompany $currentCompany, PostInventoryWriteDownService $service): Response
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $validated['as_of'] ?? now()->format('Y-m-d');

        $company = Company::findOrFail($currentCompany->id());

        $costs = $service->costsByItem($company, $asOf);
        $allowances = $service->allowancesByItem($company, $asOf);

        $items = Item::whereIn('id', array_keys($costs))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'quantity' => (float) $costs[$item->id]['quantity'],
                'unit_cost_local' => (float) $costs[$item->id]['unit_cost'],
                'cost_value_local' => (float) $costs[$item->id]['value'],
                'current_allowance' => (float) ($allowances[$item->id] ?? 0),
            ])
            ->values();

        return Inertia::render('Inventory/WriteDowns/Create', [
            'asOf' => $asOf,
            'items' => $items,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, PostInventoryWriteDownService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'as_of' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.nrv_unit' => ['required', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $writeDown = $service->post(
                Company::findOrFail($companyId),
                $validated['as_of'],
                $validated['lines'],
                $validated['description'] ?? null,
                $request->user()?->id,
            );
        } catch (InvalidWriteDownException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory-write-downs.show', $writeDown->id)
            ->with('success', 'Avalúo de deterioro contabilizado.');
    }

    public function show(int $inventoryWriteDown): Response
    {
        $writeDown = InventoryWriteDown::with([
            'lines.item:id,code,name',
            'journalEntry:id,document_number',
            'documentType:id,code,name',
        ])->findOrFail($inventoryWriteDown);

        return Inertia::render('Inventory/WriteDowns/Show', [
            'writeDown' => [
                'id' => $writeDown->id,
                'as_of' => $writeDown->as_of->format('Y-m-d'),
                'description' => $writeDown->description,
                'document_type' => $writeDown->documentType?->code,
                'journal_entry_id' => $writeDown->journal_entry_id,
                'journal_document_number' => $writeDown->journalEntry?->document_number,
            ],
            'lines' => $writeDown->lines->map(fn ($line) => [
                'line_number' => $line->line_number,
                'item_code' => $line->item?->code,
                'item_name' => $line->item?->name,
                'quantity' => (float) $line->quantity,
                'unit_cost_local' => (float) $line->unit_cost_local,
                'cost_value_local' => (float) $line->cost_value_local,
                'nrv_unit_local' => (float) $line->nrv_unit_local,
                'nrv_value_local' => (float) $line->nrv_value_local,
                'previous_allowance_local' => (float) $line->previous_allowance_local,
                'target_allowance_local' => (float) $line->target_allowance_local,
                'movement_local' => (float) $line->movement_local,
                'reason' => $line->reason,
            ]),
        ]);
    }
}
