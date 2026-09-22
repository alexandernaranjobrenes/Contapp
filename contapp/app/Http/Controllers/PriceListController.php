<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Domains\Inventory\Services\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/PriceLists/Index', [
            'priceLists' => PriceList::with('currency:id,code')
                ->withCount('lines')
                ->orderBy('code')
                ->get()
                ->map(fn (PriceList $list) => [
                    ...$list->only([
                        'id', 'code', 'name', 'currency_id', 'prices_include_tax', 'is_default', 'status',
                    ]),
                    'currency_code' => $list->currency?->code,
                    'valid_from' => $list->valid_from?->format('Y-m-d'),
                    'valid_to' => $list->valid_to?->format('Y-m-d'),
                    'lines_count' => $list->lines_count,
                    // Cuántos clientes dependen de esta lista: borrarla o
                    // vencerla sin saberlo los deja sin precio.
                    'customers_count' => BusinessPartner::where('price_list_id', $list->id)->count(),
                ]),
            'currencies' => Currency::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $request->validate($this->rules($companyId));

        if (PriceList::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe una lista con el código {$validated['code']}."])->withInput();
        }

        if ($error = $this->validityError($validated)) {
            return back()->withErrors(['valid_to' => $error])->withInput();
        }

        DB::transaction(function () use ($validated, $companyId): void {
            $this->clearDefault($validated, $companyId);

            PriceList::create([...$this->normalize($validated), 'code' => $validated['code'], 'company_id' => $companyId]);
        });

        return back()->with('success', "Lista de precios {$validated['code']} creada.");
    }

    public function update(Request $request, int $priceList, CurrentCompany $currentCompany): RedirectResponse
    {
        $list = PriceList::findOrFail($priceList);
        $companyId = $currentCompany->id();

        $validated = $request->validate(collect($this->rules($companyId))->except('code')->all());

        if ($error = $this->validityError($validated)) {
            return back()->withErrors(['valid_to' => $error])->withInput();
        }

        DB::transaction(function () use ($validated, $companyId, $list): void {
            $this->clearDefault($validated, $companyId, $list->id);

            $list->update($this->normalize($validated));
        });

        return back()->with('success', "Lista de precios {$list->code} actualizada.");
    }

    public function destroy(int $priceList): RedirectResponse
    {
        $list = PriceList::findOrFail($priceList);

        // Los clientes asignados quedarían apuntando a nada y sus facturas
        // sin precio, sin que nada lo avisara hasta la próxima venta.
        $assigned = BusinessPartner::where('price_list_id', $list->id)->count();

        if ($assigned > 0) {
            return back()->withErrors([
                'price_list' => "La lista {$list->code} está asignada a {$assigned} cliente(s). ".
                    'Reasignalos antes de eliminarla, o inactivala para dejar de usarla conservando el histórico.',
            ]);
        }

        $list->delete();

        return back()->with('success', "Lista de precios {$list->code} eliminada.");
    }

    /**
     * Los precios de una lista, artículo por artículo. Se administran dentro
     * de la lista igual que los lotes dentro del artículo: fuera de ella no
     * significan nada.
     */
    public function prices(Request $request, int $priceList, PriceResolver $resolver): Response
    {
        $list = PriceList::with('currency:id,code')->findOrFail($priceList);

        $search = trim((string) $request->query('search', ''));

        $items = Item::query()
            ->select(['items.id', 'items.code', 'items.name', 'items.avg_cost_local', 'items.is_inventory_item'])
            ->leftJoin('price_list_items', function ($join) use ($list) {
                $join->on('price_list_items.item_id', '=', 'items.id')
                    ->where('price_list_items.price_list_id', '=', $list->id);
            })
            ->addSelect('price_list_items.unit_price')
            ->where('items.status', 'active')
            ->where('items.is_sales_item', true)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('items.code', 'like', "%{$search}%")
                ->orWhere('items.name', 'like', "%{$search}%")))
            ->orderBy('items.code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/PriceLists/Prices', [
            'priceList' => [
                ...$list->only(['id', 'code', 'name', 'prices_include_tax', 'status']),
                'currency_code' => $list->currency?->code,
                'valid_from' => $list->valid_from?->format('Y-m-d'),
                'valid_to' => $list->valid_to?->format('Y-m-d'),
                // Solo tiene sentido comparar contra el costo si la lista
                // está en moneda local: un precio en dólares contra un costo
                // en colones daría un número sin significado.
                'is_local_currency' => $list->currency_id === Company::find($list->company_id)?->local_currency_id,
            ],
            'items' => $items,
            'filters' => ['search' => $search === '' ? null : $search],
            'belowCost' => $list->currency_id === Company::find($list->company_id)?->local_currency_id
                ? $resolver->linesBelowCost($list)
                : collect(),
        ]);
    }

    public function updatePrices(Request $request, int $priceList): RedirectResponse
    {
        $list = PriceList::findOrFail($priceList);
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            // Nullable: vaciar el campo QUITA el artículo de la lista, que es
            // distinto de ponerle precio cero (que sí es un precio y
            // significa regalarlo).
            'prices.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($validated, $list): void {
            foreach ($validated['prices'] as $price) {
                $itemId = (int) $price['item_id'];

                if (($price['unit_price'] ?? null) === null || $price['unit_price'] === '') {
                    PriceListItem::where('price_list_id', $list->id)->where('item_id', $itemId)->delete();

                    continue;
                }

                PriceListItem::updateOrCreate(
                    ['price_list_id' => $list->id, 'item_id' => $itemId],
                    ['unit_price' => $price['unit_price']],
                );
            }
        });

        return back()->with('success', "Precios de {$list->code} actualizados.");
    }

    /**
     * Los precios que le tocan a un cliente, para que la pantalla de emisión
     * precargue cada línea. Va por JSON y no por props porque depende del
     * cliente que se elija, que cambia sin recargar la página.
     */
    public function forCustomer(Request $request, CurrentCompany $currentCompany, PriceResolver $resolver): JsonResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'business_partner_id' => ['nullable', 'integer', Rule::exists('business_partners', 'id')->where('company_id', $companyId)],
            'date' => ['nullable', 'date'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
        ]);

        $result = $resolver->priceMap(
            Company::findOrFail($companyId),
            isset($validated['business_partner_id'])
                ? BusinessPartner::find($validated['business_partner_id'])
                : null,
            $validated['date'] ?? now()->format('Y-m-d'),
            isset($validated['currency_id']) ? (int) $validated['currency_id'] : null,
        );

        return response()->json([
            'prices' => $result['prices'],
            'reason' => $result['reason'],
            'list' => $result['list'] === null ? null : [
                'code' => $result['list']->code,
                'name' => $result['list']->name,
                'prices_include_tax' => $result['list']->prices_include_tax,
            ],
        ]);
    }

    private function rules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'prices_include_tax' => ['boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'is_default' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    private function validityError(array $validated): ?string
    {
        $from = $validated['valid_from'] ?? null;
        $to = $validated['valid_to'] ?? null;

        if ($from !== null && $to !== null && $to < $from) {
            return 'La vigencia termina antes de empezar: la lista no aplicaría ningún día.';
        }

        return null;
    }

    /**
     * Solo puede haber una lista predeterminada: dos harían que el precio de
     * un cliente sin lista propia dependiera del orden de la consulta.
     */
    private function clearDefault(array $validated, int $companyId, ?int $exceptId = null): void
    {
        if (! ($validated['is_default'] ?? false)) {
            return;
        }

        PriceList::where('company_id', $companyId)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    private function normalize(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'currency_id' => $validated['currency_id'],
            'prices_include_tax' => $validated['prices_include_tax'] ?? false,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_to' => $validated['valid_to'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
            'status' => $validated['status'],
        ];
    }
}
