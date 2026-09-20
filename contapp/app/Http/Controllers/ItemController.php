<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(): Response
    {
        $items = Item::with([
            'itemGroup:id,code,name',
            'unitOfMeasure:id,code,name',
        ])
            ->withSum('stockLevels as on_hand', 'on_hand')
            ->orderBy('code')
            ->get([
                'id', 'code', 'name', 'item_group_id', 'uom_id', 'barcode',
                'is_inventory_item', 'is_sales_item', 'is_purchase_item', 'tracks_lots',
                'tax_rate_id', 'avg_cost_local', 'avg_cost_foreign', 'status',
            ]);

        return Inertia::render('Inventory/Items/Index', [
            'items' => $items,
            'itemGroups' => ItemGroup::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'unitsOfMeasure' => UnitOfMeasure::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'taxRates' => TaxRate::orderBy('code')->get(['id', 'code', 'name', 'percentage']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->rules($companyId));

        if (Item::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un artículo con el código {$validated['code']}."])->withInput();
        }

        Item::create([
            ...$this->normalize($validated),
            'code' => $validated['code'],
            'company_id' => $companyId,
        ]);

        return back()->with('success', "Artículo {$validated['code']} creado.");
    }

    /**
     * El costo promedio NO se edita desde acá: lo mantiene exclusivamente el
     * motor de movimientos de stock (Fase 2), porque cada cambio de costo
     * tiene que generar su asiento. Un artículo con existencia tampoco puede
     * dejar de ser de inventario — ver abajo.
     */
    public function update(Request $request, int $item, CurrentCompany $currentCompany): RedirectResponse
    {
        $item = Item::findOrFail($item);

        $validated = $request->validate(
            collect($this->rules($currentCompany->id()))->except('code')->all()
        );

        if (! $validated['is_inventory_item'] && $item->is_inventory_item) {
            if (bccomp($item->onHand(), '0.000000', 6) !== 0) {
                return back()->withErrors([
                    'is_inventory_item' => "El artículo {$item->code} todavía tiene existencias; no se puede convertir en servicio hasta dejarlo en cero.",
                ]);
            }
        }

        $item->update($this->normalize($validated));

        return back()->with('success', "Artículo {$item->code} actualizado.");
    }

    public function destroy(int $item): RedirectResponse
    {
        $item = Item::findOrFail($item);

        if (bccomp($item->onHand(), '0.000000', 6) !== 0) {
            return back()->withErrors([
                'item' => "El artículo {$item->code} todavía tiene existencias; no se puede eliminar. Podés inactivarlo en su lugar.",
            ]);
        }

        // Igual que en almacenes: las filas en cero son rastro de existencia
        // pasada, no movimientos. El kardex inviolable llega en Fase 2 y va a
        // tener que consultarse acá también.
        ItemWarehouse::where('item_id', $item->id)->delete();

        $item->delete();

        return back()->with('success', "Artículo {$item->code} eliminado.");
    }

    private function rules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'item_group_id' => [
                'nullable',
                Rule::exists('item_groups', 'id')->where('company_id', $companyId),
            ],
            'uom_id' => [
                'required',
                Rule::exists('units_of_measure', 'id')->where('company_id', $companyId),
            ],
            'barcode' => ['nullable', 'string', 'max:255'],
            'is_inventory_item' => ['boolean'],
            'is_sales_item' => ['boolean'],
            'is_purchase_item' => ['boolean'],
            'tracks_lots' => ['boolean'],
            // company_id NULL en tax_rates es el catálogo nacional compartido
            // (ver GlobalOrOwnCompanyScope): exigir company_id = la compañía
            // rechazaría el IVA nacional, que es el caso normal.
            'tax_rate_id' => [
                'nullable',
                Rule::exists('tax_rates', 'id')->where(
                    fn ($query) => $query->where(
                        fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)
                    )
                ),
            ],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    private function normalize(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'item_group_id' => $validated['item_group_id'] ?? null,
            'uom_id' => $validated['uom_id'],
            'barcode' => $validated['barcode'] ?? null,
            'is_inventory_item' => $validated['is_inventory_item'] ?? false,
            'is_sales_item' => $validated['is_sales_item'] ?? false,
            'is_purchase_item' => $validated['is_purchase_item'] ?? false,
            // Un servicio no lleva kardex, así que tampoco puede llevar lotes:
            // no hay existencia que rastrear.
            'tracks_lots' => ($validated['is_inventory_item'] ?? false) && ($validated['tracks_lots'] ?? false),
            'tax_rate_id' => $validated['tax_rate_id'] ?? null,
            'status' => $validated['status'],
        ];
    }
}
