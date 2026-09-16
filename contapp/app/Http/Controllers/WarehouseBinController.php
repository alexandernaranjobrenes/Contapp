<?php

namespace App\Http\Controllers;

use App\Domains\Inventory\Models\ItemBin;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseBinController extends Controller
{
    /**
     * Las ubicaciones se administran dentro de su almacén: fuera de él no
     * significan nada, y el CompanyScope del almacén es lo que las aísla.
     */
    public function index(int $warehouse): Response
    {
        $warehouse = Warehouse::findOrFail($warehouse);

        $bins = WarehouseBin::where('warehouse_id', $warehouse->id)
            ->withSum('stockLevels as on_hand', 'on_hand')
            ->withCount('stockLevels as items_count')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status']);

        return Inertia::render('Inventory/Warehouses/Bins', [
            'warehouse' => $warehouse->only(['id', 'code', 'name', 'uses_bins']),
            'bins' => $bins,
        ]);
    }

    public function store(Request $request, int $warehouse): RedirectResponse
    {
        $warehouse = Warehouse::findOrFail($warehouse);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if (WarehouseBin::where('warehouse_id', $warehouse->id)->where('code', $validated['code'])->exists()) {
            return back()->withErrors([
                'code' => "El almacén {$warehouse->code} ya tiene una ubicación con el código {$validated['code']}.",
            ])->withInput();
        }

        WarehouseBin::create([...$validated, 'warehouse_id' => $warehouse->id, 'name' => $validated['name'] ?? null]);

        return back()->with('success', "Ubicación {$validated['code']} creada.");
    }

    public function update(Request $request, int $warehouse, int $bin): RedirectResponse
    {
        $bin = $this->binOf($warehouse, $bin);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $bin->update([...$validated, 'name' => $validated['name'] ?? null]);

        return back()->with('success', "Ubicación {$bin->code} actualizada.");
    }

    public function destroy(int $warehouse, int $bin): RedirectResponse
    {
        $bin = $this->binOf($warehouse, $bin);

        $withStock = ItemBin::where('warehouse_bin_id', $bin->id)->where('on_hand', '!=', 0)->exists();

        if ($withStock) {
            return back()->withErrors([
                'bin' => "La ubicación {$bin->code} todavía tiene existencias; moverlas primero, o inactivarla en su lugar.",
            ]);
        }

        ItemBin::where('warehouse_bin_id', $bin->id)->delete();
        $bin->delete();

        return back()->with('success', "Ubicación {$bin->code} eliminada.");
    }

    /**
     * Resuelve la ubicación PASANDO por su almacén, que sí trae CompanyScope:
     * buscarla directo por id permitiría tocar la de otra compañía.
     */
    private function binOf(int $warehouseId, int $binId): WarehouseBin
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        return WarehouseBin::where('warehouse_id', $warehouse->id)->findOrFail($binId);
    }
}
