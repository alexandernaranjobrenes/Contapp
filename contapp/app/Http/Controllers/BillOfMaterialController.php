<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Exceptions\InvalidBillOfMaterialException;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\BillOfMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillOfMaterialController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/BillsOfMaterials/Index', [
            'billsOfMaterials' => BillOfMaterial::with('item:id,code,name')
                ->withCount('lines')
                ->orderBy('code')
                ->get()
                ->map(fn (BillOfMaterial $bom) => [
                    ...$bom->only(['id', 'code', 'name', 'item_id', 'is_default', 'status', 'notes']),
                    'output_quantity' => (float) $bom->output_quantity,
                    'item_code' => $bom->item?->code,
                    'item_name' => $bom->item?->name,
                    'lines_count' => $bom->lines_count,
                ]),
            // Solo artículos de inventario: no se fabrica un servicio.
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $request->validate($this->rules($companyId));

        if (BillOfMaterial::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe una receta con el código {$validated['code']}."])->withInput();
        }

        DB::transaction(function () use ($validated, $companyId): void {
            $this->clearDefault($validated, $companyId, (int) $validated['item_id']);

            BillOfMaterial::create([
                ...$this->normalize($validated),
                'code' => $validated['code'],
                'item_id' => $validated['item_id'],
                'company_id' => $companyId,
            ]);
        });

        return back()->with('success', "Receta {$validated['code']} creada.");
    }

    public function update(Request $request, int $billOfMaterial, CurrentCompany $currentCompany): RedirectResponse
    {
        $bom = BillOfMaterial::findOrFail($billOfMaterial);
        $companyId = $currentCompany->id();

        $validated = $request->validate(
            collect($this->rules($companyId))->except(['code', 'item_id'])->all()
        );

        DB::transaction(function () use ($validated, $companyId, $bom): void {
            $this->clearDefault($validated, $companyId, $bom->item_id, $bom->id);

            $bom->update($this->normalize($validated));
        });

        return back()->with('success', "Receta {$bom->code} actualizada.");
    }

    public function destroy(int $billOfMaterial): RedirectResponse
    {
        $bom = BillOfMaterial::findOrFail($billOfMaterial);

        // Las órdenes que la usaron quedarían sin decir con qué receta se
        // fabricaron, que es justo lo que hay que poder reconstruir cuando
        // una tanda sale mal.
        $orders = DB::table('production_orders')->where('bill_of_material_id', $bom->id)->count();

        if ($orders > 0) {
            return back()->withErrors([
                'bill_of_material' => "La receta {$bom->code} ya se usó en {$orders} orden(es) de fabricación. ".
                    'Inactivala en vez de eliminarla: el histórico tiene que poder decir con qué se fabricó.',
            ]);
        }

        $bom->delete();

        return back()->with('success', "Receta {$bom->code} eliminada.");
    }

    /**
     * Los componentes de una receta. Se administran dentro de ella igual que
     * los precios dentro de su lista: fuera no significan nada.
     */
    public function lines(int $billOfMaterial, BillOfMaterialService $service): Response
    {
        $bom = BillOfMaterial::with([
            'item:id,code,name',
            'lines.componentItem:id,code,name,uom_id,avg_cost_local',
            'lines.componentItem.unitOfMeasure:id,code',
            'lines.warehouse:id,code',
        ])->findOrFail($billOfMaterial);

        return Inertia::render('Inventory/BillsOfMaterials/Lines', [
            'bom' => [
                ...$bom->only(['id', 'code', 'name', 'item_id', 'status', 'is_default']),
                'output_quantity' => (float) $bom->output_quantity,
                'item_code' => $bom->item?->code,
                'item_name' => $bom->item?->name,
            ],
            'lines' => $bom->lines->map(fn (BillOfMaterialLine $line) => [
                'id' => $line->id,
                'component_item_id' => $line->component_item_id,
                'item_code' => $line->componentItem?->code,
                'item_name' => $line->componentItem?->name,
                'uom' => $line->componentItem?->unitOfMeasure?->code,
                'quantity' => (float) $line->quantity,
                'scrap_percentage' => (float) $line->scrap_percentage,
                'warehouse_id' => $line->warehouse_id,
                'warehouse_code' => $line->warehouse?->code,
                'notes' => $line->notes,
            ]),
            'items' => Item::where('status', 'active')
                ->where('is_inventory_item', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            // Explotada a su propio rendimiento: es la forma más útil de
            // revisarla, porque muestra directamente si hay con qué fabricar
            // un lote completo.
            'preview' => $service->explode($bom, $bom->output_quantity),
        ]);
    }

    public function updateLines(Request $request, int $billOfMaterial, BillOfMaterialService $service): RedirectResponse
    {
        $bom = BillOfMaterial::findOrFail($billOfMaterial);
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.component_item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.scrap_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $componentIds = array_map(fn (array $l) => (int) $l['component_item_id'], $validated['lines']);

        if (count($componentIds) !== count(array_unique($componentIds))) {
            return back()->withErrors([
                'lines' => 'Un componente no puede aparecer dos veces en la misma receta: se emitiría el doble sin que nadie lo note.',
            ]);
        }

        try {
            $service->assertNoCycle(
                Company::findOrFail($companyId), $bom->item_id, $componentIds, $bom->id
            );
        } catch (InvalidBillOfMaterialException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        DB::transaction(function () use ($validated, $bom, $componentIds): void {
            // Las líneas que ya no vienen se borran: la receta es lo que se
            // guardó, no lo que se guardó alguna vez.
            BillOfMaterialLine::where('bill_of_material_id', $bom->id)
                ->when($componentIds !== [], fn ($q) => $q->whereNotIn('component_item_id', $componentIds))
                ->delete();

            foreach ($validated['lines'] as $line) {
                BillOfMaterialLine::updateOrCreate(
                    ['bill_of_material_id' => $bom->id, 'component_item_id' => (int) $line['component_item_id']],
                    [
                        'quantity' => $line['quantity'],
                        'scrap_percentage' => $line['scrap_percentage'] ?? 0,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'notes' => $line['notes'] ?? null,
                    ],
                );
            }
        });

        return back()->with('success', "Componentes de {$bom->code} actualizados.");
    }

    /**
     * La explosión para una cantidad dada. Va por JSON porque la pantalla de
     * emisión la pide al escribir la cantidad, sin recargar.
     */
    public function explode(Request $request, int $billOfMaterial, BillOfMaterialService $service): JsonResponse
    {
        $bom = BillOfMaterial::findOrFail($billOfMaterial);
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
        ]);

        try {
            $lines = $service->explode(
                $bom,
                $validated['quantity'],
                isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            );
        } catch (InvalidBillOfMaterialException $e) {
            return response()->json(['message' => $e->getMessage(), 'lines' => []], 422);
        }

        return response()->json([
            'bom' => ['code' => $bom->code, 'output_quantity' => (float) $bom->output_quantity],
            'lines' => $lines,
        ]);
    }

    private function rules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'item_id' => [
                'required',
                Rule::exists('items', 'id')->where('company_id', $companyId)->where('is_inventory_item', true),
            ],
            // Mayor que cero: una receta que rinde cero no se puede explotar
            // y sería una división por cero al calcular el factor.
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'is_default' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * La predeterminada es por PRODUCTO, no por compañía: cada artículo puede
     * tener la suya, y varias recetas del mismo producto —fórmula de verano
     * y de invierno— son legítimas.
     */
    private function clearDefault(array $validated, int $companyId, int $itemId, ?int $exceptId = null): void
    {
        if (! ($validated['is_default'] ?? false)) {
            return;
        }

        BillOfMaterial::where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    private function normalize(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'output_quantity' => $validated['output_quantity'],
            'is_default' => $validated['is_default'] ?? false,
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
