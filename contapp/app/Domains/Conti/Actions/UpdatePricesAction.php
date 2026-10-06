<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Models\User;

/** Precios de una lista, como en Listas de precios → Precios (PriceListController::updatePrices). */
final class UpdatePricesAction extends BaseContiAction
{
    public function key(): string
    {
        return 'actualizar_precios';
    }

    public function label(): string
    {
        return 'Cambiar precios de una lista';
    }

    public function description(): string
    {
        return 'Pone, cambia o quita el precio de uno o varios artículos en una lista de precios. Un precio vacío (null) quita el artículo de la lista; cero es un precio (regalarlo).';
    }

    public function screen(): string
    {
        return 'inventory.price_lists';
    }

    public function fields(): array
    {
        return [
            'lista' => 'código de la lista de precios',
            'precios' => 'lista de {articulo: código, precio: monto o null para quitarlo de la lista}',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $this->validate($input, [
            'lista' => ['required', 'string'],
            'precios' => ['required', 'array', 'min:1', 'max:500'],
            'precios.*.articulo' => ['required', 'string'],
            'precios.*.precio' => ['nullable', 'numeric', 'min:0'],
        ]);

        $list = $this->byCode(PriceList::class, $input['lista'], 'lista', 'una lista de precios');
        $list->loadMissing('currency:id,code');

        $prices = [];
        $rows = [];

        foreach (array_values($input['precios']) as $i => $line) {
            $item = $this->byCode(Item::class, $line['articulo'], "precios.{$i}.articulo", 'un artículo');
            $new = ($line['precio'] ?? null) === null || $line['precio'] === '' ? null : bcadd((string) $line['precio'], '0', 2);
            $current = PriceListItem::where('price_list_id', $list->id)->where('item_id', $item->id)->value('unit_price');

            $prices[] = ['item_id' => $item->id, 'unit_price' => $new];
            $rows[] = [
                "{$item->code} {$item->name}",
                $current !== null ? $this->money($current) : 'sin precio',
                $new !== null ? $this->money($new) : 'se quita de la lista',
            ];
        }

        return new PreparedAction(
            title: "Precios de la lista {$list->code} — {$list->name} ({$list->currency?->code})",
            items: $this->items([
                'Lista' => "{$list->code} {$list->name}",
                'Moneda' => $list->currency?->code,
                'Incluyen IVA' => $list->prices_include_tax ? 'Sí' : 'No',
                'Artículos' => (string) count($prices),
            ]),
            payload: ['price_list_id' => $list->id, 'prices' => $prices],
            input: $input,
            table: ['columnas' => ['Artículo', 'Precio actual', 'Precio nuevo'], 'filas' => $rows],
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $list = PriceList::findOrFail($prepared->payload['price_list_id']);

        foreach ($prepared->payload['prices'] as $price) {
            if ($price['unit_price'] === null) {
                PriceListItem::where('price_list_id', $list->id)->where('item_id', $price['item_id'])->delete();

                continue;
            }

            PriceListItem::updateOrCreate(
                ['price_list_id' => $list->id, 'item_id' => $price['item_id']],
                ['unit_price' => $price['unit_price']],
            );
        }

        return new ActionResult("Precios de {$list->code} actualizados.", route('price-lists.prices', $list->id), 'Ver los precios de la lista');
    }
}
