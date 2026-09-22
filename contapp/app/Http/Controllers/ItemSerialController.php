<?php

namespace App\Http\Controllers;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\ItemWarehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta y mantenimiento del maestro de series de un artículo.
 *
 * Las series NO se dan de alta acá: nacen con la entrada de mercancía que las
 * trajo, igual que la existencia. Lo que sí se edita es lo que no sabe el
 * movimiento — garantía y notas — y lo que es una decisión posterior: dar de
 * baja una unidad que se dañó.
 */
class ItemSerialController extends Controller
{
    public function index(Request $request, int $item): Response
    {
        $item = Item::findOrFail($item);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(array_keys(ItemSerial::STATUSES))],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? null;

        $serials = ItemSerial::with([
            'warehouse:id,code,name',
            'bin:id,code',
            'lot:id,code',
            'receivedDocument:id,number',
            'issuedDocument:id,number,business_partner_id',
            'issuedDocument.businessPartner:id,code,name',
        ])
            ->where('item_id', $item->id)
            ->when($search !== '', fn ($q) => $q->where('serial_number', 'like', "%{$search}%"))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'in_stock' THEN 0 ELSE 1 END")
            ->orderBy('serial_number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Items/Serials', [
            'item' => $item->only(['id', 'code', 'name', 'tracks_serials', 'is_inventory_item']),
            'serials' => $serials,
            'filters' => ['search' => $search === '' ? null : $search, 'status' => $status],
            'statuses' => ItemSerial::STATUSES,
            // El invariante de la capa, a la vista: las series en existencia
            // tienen que ser tantas como la existencia del artículo. Si no
            // cuadran hay una unidad que el inventario cree tener y no está.
            'reconciliation' => [
                'serials_in_stock' => ItemSerial::where('item_id', $item->id)->inStock()->count(),
                'on_hand' => (float) ItemWarehouse::where('item_id', $item->id)->sum('on_hand'),
            ],
        ]);
    }

    /**
     * Solo garantía y notas: el resto lo escribe el movimiento que la trajo, y
     * dejar editar el almacén o el estado a mano rompería el invariante con
     * la existencia sin que nada lo avisara.
     */
    public function update(Request $request, int $item, int $serial): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $serial = ItemSerial::where('item_id', $item->id)->findOrFail($serial);

        $validated = $request->validate([
            'warranty_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $serial->update([
            'warranty_until' => $validated['warranty_until'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', "Serie {$serial->serial_number} actualizada.");
    }

    /**
     * Dar de baja NO es lo mismo que entregar, y por eso no pasa por acá sino
     * por una salida de mercancía: una unidad que se destruye tiene que
     * salir del inventario con su asiento, igual que cualquier otra baja.
     *
     * Lo que sí hace este endpoint es marcar como dada de baja una serie que
     * YA salió del inventario, para distinguir en el maestro la que se vendió
     * de la que se destruyó — dos cosas que la garantía trata distinto.
     */
    public function scrap(int $item, int $serial): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $serial = ItemSerial::where('item_id', $item->id)->findOrFail($serial);

        if ($serial->status === 'in_stock') {
            return back()->withErrors([
                'serial' => "La serie {$serial->serial_number} todavía está en existencia. ".
                    'Para darla de baja registrá una salida de mercancía, que genera el asiento; '.
                    'marcarla acá dejaría el inventario diciendo que la tiene.',
            ]);
        }

        $serial->update(['status' => 'scrapped']);

        return back()->with('success', "Serie {$serial->serial_number} marcada como dada de baja.");
    }
}
