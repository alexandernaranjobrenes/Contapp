<?php

namespace App\Http\Controllers;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemLot;
use App\Domains\Inventory\Models\ItemLotStock;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Inventory\Services\ItemLotResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemLotController extends Controller
{
    public function __construct(private readonly ItemLotResolver $lotResolver) {}

    /**
     * Los lotes se administran dentro de su artículo: fuera de él no
     * significan nada, y el CompanyScope del artículo es lo que los aísla —
     * mismo criterio que WarehouseBinController con su almacén.
     */
    public function index(int $item): Response
    {
        $item = Item::findOrFail($item);

        $lots = ItemLot::where('item_id', $item->id)
            ->withSum('stockLevels as on_hand', 'on_hand')
            ->fefo()
            ->get(['id', 'code', 'expires_at', 'status', 'notes'])
            ->map(fn (ItemLot $lot) => [
                ...$lot->only(['id', 'code', 'expires_at', 'status', 'notes']),
                'on_hand' => (float) ($lot->on_hand ?? 0),
                // Se resuelve acá y no en el componente: "vencido" depende de
                // una fecha, y duplicar esa comparación en Vue es justo el
                // error que ya se corrigió una vez en el módulo de licencias
                // (ver docs/decisiones.md, display_status).
                'is_expired' => $lot->isExpiredOn(now()),
                'days_to_expiry' => $lot->expires_at
                    ? (int) now()->startOfDay()->diffInDays($lot->expires_at, false)
                    : null,
            ]);

        return Inertia::render('Inventory/Items/Lots', [
            'item' => $item->only(['id', 'code', 'name', 'tracks_lots']),
            'lots' => $lots,
        ]);
    }

    public function store(Request $request, int $item): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $validated = $this->validated($request, $item);

        if (ItemLot::where('item_id', $item->id)->where('code', $validated['code'])->exists()) {
            return back()->withErrors([
                'code' => "El artículo {$item->code} ya tiene un lote con el número {$validated['code']}.",
            ])->withInput();
        }

        ItemLot::create([...$validated, 'item_id' => $item->id]);

        return back()->with('success', "Lote {$validated['code']} creado.");
    }

    /**
     * El número de lote no se edita una vez creado: es la identidad con la
     * que ya quedó grabado en el kardex, y cambiarlo reescribiría la
     * trazabilidad hacia atrás. Solo se corrigen vencimiento, estado y notas
     * — mismo criterio que el código de un centro de costo.
     */
    public function update(Request $request, int $item, int $lot): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $lot = $this->lotOf($item, $lot);

        $validated = $request->validate([
            'expires_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $lot->update($validated);

        return back()->with('success', "Lote {$lot->code} actualizado.");
    }

    /**
     * Un lote con movimientos no se borra jamás: es trazabilidad
     * contabilizada, la misma regla innegociable que rige para una cuenta
     * con movimientos o un asiento contabilizado. Para sacarlo de
     * circulación se retiene (status 'blocked').
     */
    public function destroy(int $item, int $lot): RedirectResponse
    {
        $item = Item::findOrFail($item);
        $lot = $this->lotOf($item, $lot);

        if (StockJournal::where('item_lot_id', $lot->id)->exists()) {
            return back()->withErrors([
                'lot' => "El lote {$lot->code} ya tiene movimientos de inventario; no se puede eliminar. Podés retenerlo en su lugar.",
            ]);
        }

        $lot->delete();

        return back()->with('success', "Lote {$lot->code} eliminado.");
    }

    /**
     * Trazabilidad de un lote: por dónde pasó y dónde está hoy. Es la razón
     * de ser de todo el manejo de lotes — la pregunta que hay que poder
     * responder en un recall, y la que justifica el índice
     * (item_lot_id, posting_date) del kardex.
     */
    public function trace(int $item, int $lot): Response
    {
        $item = Item::findOrFail($item);
        $lot = $this->lotOf($item, $lot);

        $movements = StockJournal::where('item_lot_id', $lot->id)
            ->with([
                'warehouse:id,code,name',
                'bin:id,code',
                'documentLine.document:id,operation,description',
                'journalEntry:id,document_number',
            ])
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get()
            ->map(fn (StockJournal $row) => [
                'id' => $row->id,
                'posting_date' => $row->posting_date->format('Y-m-d'),
                'direction' => $row->direction,
                'operation' => $row->documentLine?->document?->operation,
                'description' => $row->documentLine?->document?->description,
                'warehouse' => $row->warehouse?->code,
                'bin' => $row->bin?->code,
                'quantity' => (float) $row->quantity,
                'journal_entry' => $row->journalEntry?->document_number,
            ]);

        $balances = ItemLotStock::where('item_lot_id', $lot->id)
            ->where('on_hand', '>', 0)
            ->with(['warehouse:id,code,name', 'bin:id,code'])
            ->get()
            ->map(fn (ItemLotStock $row) => [
                'warehouse' => $row->warehouse?->code,
                'bin' => $row->bin?->code,
                'on_hand' => (float) $row->on_hand,
            ]);

        return Inertia::render('Inventory/Items/LotTrace', [
            'item' => $item->only(['id', 'code', 'name']),
            'lot' => [
                ...$lot->only(['id', 'code', 'expires_at', 'status', 'notes']),
                'is_expired' => $lot->isExpiredOn(now()),
            ],
            'movements' => $movements,
            'balances' => $balances,
        ]);
    }

    /**
     * Lotes que el formulario de movimientos puede ofrecer para una línea.
     *
     * Es un endpoint aparte y no un prop de la pantalla a propósito: mandar
     * todos los lotes de todos los artículos en cada carga del formulario
     * crece sin techo (un artículo con rotación alta acumula cientos de
     * lotes al año), mientras que esto trae solo los de la línea que se está
     * digitando. Mismo criterio que el lookup de correo en el alta de
     * usuarios.
     *
     * En una ENTRADA se ofrecen todos los lotes vigentes del artículo: se
     * puede recibir contra cualquiera. En una SALIDA se ofrece la sugerencia
     * FEFO, que ya excluye vencidos, retenidos y sin saldo.
     */
    public function options(Request $request, int $item): \Illuminate\Http\JsonResponse
    {
        $item = Item::findOrFail($item);

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'warehouse_bin_id' => ['nullable', 'integer'],
            'operation' => ['nullable', 'string'],
        ]);

        if (! $item->tracks_lots) {
            return response()->json(['tracks_lots' => false, 'lots' => []]);
        }

        $isIssue = in_array($validated['operation'] ?? '', ['goods_issue', 'sales_issue', 'production_issue'], true);

        if ($isIssue && isset($validated['warehouse_id'])) {
            $lots = $this->lotResolver
                ->suggestForIssue(
                    $item,
                    (int) $validated['warehouse_id'],
                    isset($validated['warehouse_bin_id']) ? (int) $validated['warehouse_bin_id'] : null,
                    now(),
                )
                ->map(fn (array $row) => [
                    'id' => $row['lot']->id,
                    'code' => $row['lot']->code,
                    'expires_at' => $row['lot']->expires_at?->format('Y-m-d'),
                    'on_hand' => (float) $row['on_hand'],
                ]);

            return response()->json(['tracks_lots' => true, 'fefo' => true, 'lots' => $lots]);
        }

        $lots = ItemLot::where('item_id', $item->id)
            ->where('status', 'active')
            ->fefo()
            ->get(['id', 'code', 'expires_at'])
            ->reject(fn (ItemLot $lot) => $lot->isExpiredOn(now()))
            ->map(fn (ItemLot $lot) => [
                'id' => $lot->id,
                'code' => $lot->code,
                'expires_at' => $lot->expires_at?->format('Y-m-d'),
                'on_hand' => null,
            ])
            ->values();

        return response()->json(['tracks_lots' => true, 'fefo' => false, 'lots' => $lots]);
    }

    private function validated(Request $request, Item $item): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function lotOf(Item $item, int $lotId): ItemLot
    {
        $lot = ItemLot::where('item_id', $item->id)->find($lotId);

        abort_unless($lot !== null, 404);

        return $lot;
    }
}
