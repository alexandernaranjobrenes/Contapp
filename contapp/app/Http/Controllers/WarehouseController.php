<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Services\GlDeterminationScopeService;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(GlDeterminationScopeService $scopes): Response
    {
        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => Warehouse::orderBy('code')->get([
                'id', 'code', 'name', 'address', 'is_default', 'uses_bins', 'status',
            ]),
            // Cuentas por almacén: el escalón que permite separar la
            // contabilidad de una bodega o una sucursal del resto.
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->description_es]),
            'accountCategories' => collect(GlDeterminationScopeService::CARD_CATEGORIES['warehouse'])
                ->mapWithKeys(fn (string $c) => [$c => GlDetermination::CATEGORIES[$c]])
                ->all(),
            'warehouseAccounts' => $scopes->forScopeLevel(app(CurrentCompany::class)->id(), 'warehouse'),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'uses_bins' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')
                ->where('company_id', app(CurrentCompany::class)->id())
                ->where('accepts_posting', true)],
        ]);

        if (Warehouse::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un almacén con el código {$validated['code']}."])->withInput();
        }

        DB::transaction(function () use ($validated, $companyId) {
            $isDefault = $validated['is_default'] ?? false;

            if ($isDefault) {
                $this->clearDefault($companyId);
            }

            $warehouse = Warehouse::create([
                ...collect($validated)->except('accounts')->all(),
                'company_id' => $companyId,
                'address' => $validated['address'] ?? null,
                'is_default' => $isDefault,
            ]);

            app(GlDeterminationScopeService::class)
                ->sync($companyId, 'warehouse', $warehouse->id, $validated['accounts'] ?? []);
        });

        return back()->with('success', "Almacén {$validated['code']} creado.");
    }

    public function update(Request $request, int $warehouse): RedirectResponse
    {
        $warehouse = Warehouse::findOrFail($warehouse);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'uses_bins' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')
                ->where('company_id', app(CurrentCompany::class)->id())
                ->where('accepts_posting', true)],
        ]);

        DB::transaction(function () use ($validated, $warehouse) {
            $isDefault = $validated['is_default'] ?? false;

            if ($isDefault) {
                $this->clearDefault($warehouse->company_id, $warehouse->id);
            }

            $warehouse->update([
                ...collect($validated)->except('accounts')->all(),
                'address' => $validated['address'] ?? null,
                'is_default' => $isDefault,
            ]);

            app(GlDeterminationScopeService::class)
                ->sync($warehouse->company_id, 'warehouse', $warehouse->id, $validated['accounts'] ?? []);
        });

        return back()->with('success', "Almacén {$warehouse->code} actualizado.");
    }

    public function destroy(int $warehouse): RedirectResponse
    {
        $warehouse = Warehouse::findOrFail($warehouse);

        $withStock = ItemWarehouse::where('warehouse_id', $warehouse->id)
            ->where('on_hand', '!=', 0)
            ->exists();

        if ($withStock) {
            return back()->withErrors([
                'warehouse' => "El almacén {$warehouse->code} todavía tiene existencias; trasladalas o dalas de baja primero, o inactivá el almacén en su lugar.",
            ]);
        }

        DB::transaction(function () use ($warehouse) {
            // Filas en cero: son el rastro de un artículo que alguna vez tuvo
            // existencia acá, no un movimiento contabilizado. El kardex —que
            // sí es inviolable— vive en stock_journals, y en Fase 2 este
            // borrado va a tener que consultarlo también.
            ItemWarehouse::where('warehouse_id', $warehouse->id)->delete();

            $warehouse->delete();
        });

        return back()->with('success', "Almacén {$warehouse->code} eliminado.");
    }

    /**
     * Un solo almacén por defecto por compañía: marcar uno desmarca al
     * anterior, en vez de rechazar el guardado y obligar a dos pasos.
     */
    private function clearDefault(int $companyId, ?int $exceptId = null): void
    {
        Warehouse::where('company_id', $companyId)
            ->where('is_default', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }
}
