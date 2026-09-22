<?php

namespace App\Domains\Inventory\Services;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\DataTransferObjects\PriceResolution;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use Illuminate\Support\Collection;

/**
 * Qué precio le corresponde a un artículo para un cliente en una fecha.
 *
 * ── Precedencia ──────────────────────────────────────────────────────────
 *
 *     lista del cliente  →  lista de su categoría  →  predeterminada
 *
 * Mismo patrón que la determinación de cuentas y que los niveles de reorden:
 * lo específico gana y lo general cubre el resto. El escalón de la categoría
 * es por volumen: asignarle la lista de mayoreo a 400 clientes uno por uno
 * no es configuración, es transcripción.
 *
 * ── Lo que deliberadamente NO hace ───────────────────────────────────────
 *
 * **No cae a la lista predeterminada cuando el artículo falta en la lista
 * del cliente.** Es la decisión menos obvia del servicio y la más importante.
 * Si un mayorista tiene lista propia y a un artículo se le olvidó ponerle
 * precio ahí, caer a la lista general le cobraría el precio de mostrador —
 * más caro que lo pactado, en silencio, y el error aparece cuando el cliente
 * reclama. Sin precio, en cambio, la línea llega vacía y alguien la digita:
 * exactamente lo que pasaba antes de que existieran las listas, así que no
 * hay regresión y sí una pregunta visible.
 *
 * **No convierte de moneda.** Una lista en colones no da precio a una
 * factura en dólares. Convertir al tipo de cambio del día haría que el precio
 * cambiara solo, todos los días, sin que nadie lo decidiera.
 *
 * **No bloquea nada.** El precio resuelto es una sugerencia que llega a la
 * línea; quien factura puede cambiarlo. Un sistema que impide vender porque
 * falta configurar una lista es un sistema que no se usa.
 */
class PriceResolver
{
    /**
     * El precio de UN artículo. Para una factura entera usar priceMap(), que
     * resuelve todo el catálogo de una sola consulta.
     */
    public function resolve(
        Company $company,
        int $itemId,
        ?BusinessPartner $customer,
        string $date,
        ?int $currencyId = null,
    ): PriceResolution {
        $list = $this->listFor($company, $customer);

        if ($list === null) {
            return PriceResolution::none('no_list');
        }

        if (! $list->isValidOn($date)) {
            return PriceResolution::none('list_not_valid', $list);
        }

        if ($currencyId !== null && $list->currency_id !== $currencyId) {
            return PriceResolution::none('currency_mismatch', $list);
        }

        $price = PriceListItem::where('price_list_id', $list->id)
            ->where('item_id', $itemId)
            ->value('unit_price');

        if ($price === null) {
            return PriceResolution::none('item_not_in_list', $list);
        }

        return PriceResolution::found((string) $price, $list);
    }

    /**
     * Todos los precios que aplican a un cliente, como item_id => precio.
     *
     * La pantalla de emisión los carga de una vez al elegir el cliente: pedir
     * el precio artículo por artículo sería una consulta por línea, y una
     * factura de 40 líneas se volvería lenta justo mientras se digita.
     *
     * @return array{prices: array<int, string>, list: ?PriceList, reason: string}
     */
    public function priceMap(
        Company $company,
        ?BusinessPartner $customer,
        string $date,
        ?int $currencyId = null,
    ): array {
        $list = $this->listFor($company, $customer);

        if ($list === null) {
            return ['prices' => [], 'list' => null, 'reason' => 'no_list'];
        }

        if (! $list->isValidOn($date)) {
            return ['prices' => [], 'list' => $list, 'reason' => 'list_not_valid'];
        }

        if ($currencyId !== null && $list->currency_id !== $currencyId) {
            return ['prices' => [], 'list' => $list, 'reason' => 'currency_mismatch'];
        }

        $prices = PriceListItem::where('price_list_id', $list->id)
            ->pluck('unit_price', 'item_id')
            ->map(fn ($price) => (string) $price)
            ->all();

        return ['prices' => $prices, 'list' => $list, 'reason' => 'found'];
    }

    /**
     * La lista que le toca al cliente, en tres escalones:
     *
     *     lista del cliente  →  lista de su categoría  →  predeterminada
     *
     * El escalón del medio existe por volumen: asignarle la lista de mayoreo
     * a 400 clientes uno por uno no es configuración, es transcripción, y
     * cada cliente nuevo obliga a acordarse otra vez. Con la categoría se
     * configura una vez y todos la heredan.
     *
     * En cada escalón, si la lista asignada EXISTE se usa —vigente o no—:
     * decidir la vigencia es de isValidOn(), y sustituirla en silencio por
     * la del escalón siguiente sería cobrar de más sin avisar, que es el
     * mismo error que este servicio evita al no caer a la general cuando
     * falta el artículo.
     */
    public function listFor(Company $company, ?BusinessPartner $customer): ?PriceList
    {
        $own = $this->companyList($company, $customer?->price_list_id);

        if ($own !== null) {
            return $own;
        }

        // La categoría se lee del socio y no se precarga con with(): este
        // método lo llaman tanto resolve() como priceMap(), y desde el
        // controlador el socio llega recién buscado.
        $inherited = $this->companyList($company, $customer?->category?->price_list_id);

        if ($inherited !== null) {
            return $inherited;
        }

        return PriceList::where('company_id', $company->id)
            ->where('is_default', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Una lista por id, pero solo si pertenece a esta compañía: un id de otra
     * se ignora y se sigue bajando por la precedencia, en vez de devolver
     * precios ajenos.
     */
    private function companyList(Company $company, ?int $priceListId): ?PriceList
    {
        if ($priceListId === null) {
            return null;
        }

        return PriceList::where('company_id', $company->id)->find($priceListId);
    }

    /**
     * Precio contra costo promedio, para avisar —nunca bloquear— cuando una
     * lista está vendiendo por debajo de lo que cuesta.
     *
     * Es informativo por diseño: hay razones legítimas para vender bajo costo
     * (liquidar, producto gancho, un costo promedio inflado por un flete mal
     * asignado) y el sistema no tiene con qué distinguirlas. Lo que sí puede
     * hacer es que nadie se entere tarde.
     *
     * Solo tiene sentido si la lista está en la moneda local: comparar un
     * precio en dólares contra un costo en colones daría un número sin
     * significado.
     *
     * @return Collection<int, array<string, mixed>>  las líneas bajo costo
     */
    public function linesBelowCost(PriceList $list): Collection
    {
        return PriceListItem::query()
            ->join('items', 'items.id', '=', 'price_list_items.item_id')
            ->where('price_list_items.price_list_id', $list->id)
            ->where('items.is_inventory_item', true)
            // Un costo en cero es "nunca entró mercancía", no "es gratis":
            // compararlo daría a todo el catálogo nuevo como rentable.
            ->where('items.avg_cost_local', '>', 0)
            ->whereColumn('price_list_items.unit_price', '<', 'items.avg_cost_local')
            ->orderBy('items.code')
            ->get([
                'items.id as item_id',
                'items.code as item_code',
                'items.name as item_name',
                'price_list_items.unit_price',
                'items.avg_cost_local',
            ])
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'unit_price' => number_format((float) $row->unit_price, 5, '.', ''),
                'avg_cost_local' => number_format((float) $row->avg_cost_local, 5, '.', ''),
                'difference' => number_format((float) $row->unit_price - (float) $row->avg_cost_local, 5, '.', ''),
            ]);
    }
}
