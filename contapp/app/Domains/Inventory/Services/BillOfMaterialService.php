<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Exceptions\InvalidBillOfMaterialException;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use Illuminate\Support\Collection;

/**
 * La receta y su explosión: cuánto de cada componente hace falta para
 * fabricar una cantidad dada.
 *
 * ── La fórmula ───────────────────────────────────────────────────────────
 *
 *     factor    = cantidad a fabricar / output_quantity de la receta
 *     necesario = cantidad de la línea × factor × (1 + merma)
 *
 * El factor se aplica sobre la receta COMPLETA y no sobre una cantidad por
 * unidad previamente dividida: dividir y volver a multiplicar arrastra
 * redondeo, y en fórmulas químicas o de alimentos esa diferencia se acumula
 * lote tras lote.
 *
 * ── Qué NO hace, y por qué ───────────────────────────────────────────────
 *
 * **No cuesta nada.** La explosión dice qué y cuánto; el costo lo pone el
 * motor de movimientos al contabilizar la emisión, al promedio vigente de
 * cada componente. Un costo calculado acá sería costeo estándar, que es otro
 * sistema con sus propias variaciones y su propia discusión contable.
 *
 * **No emite sola.** Devuelve las líneas para que la pantalla las precargue
 * y alguien las revise. Es el mismo criterio que la sugerencia de compra y
 * que el precio de lista: la receta es lo que debería llevar, y quien
 * fabrica sabe si hoy lleva otra cosa.
 *
 * **Explota UN nivel.** Un subensamble se consume como el artículo que es,
 * porque ya se fabricó y está en bodega — así funciona en la planta. Lo que
 * sí atraviesa todos los niveles es la detección de ciclos.
 */
class BillOfMaterialService
{
    /**
     * Qué hace falta para fabricar $quantity unidades del producto.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function explode(BillOfMaterial $bom, string|float|int $quantity, ?int $warehouseId = null): Collection
    {
        $requested = number_format((float) $quantity, 6, '.', '');
        $output = number_format((float) $bom->output_quantity, 6, '.', '');

        if (bccomp($output, '0.000000', 6) <= 0) {
            throw new InvalidBillOfMaterialException(
                "La receta {$bom->code} rinde cero unidades; no se puede explotar."
            );
        }

        $factor = bcdiv($requested, $output, 10);

        $lines = $bom->lines()->with(['componentItem:id,code,name,uom_id,is_inventory_item,avg_cost_local', 'componentItem.unitOfMeasure:id,code'])->get();

        // La existencia de cada componente se trae de una vez: pedirla línea
        // por línea serían tantas consultas como componentes, y una receta de
        // 40 insumos haría 40.
        $onHand = ItemWarehouse::whereIn('item_id', $lines->pluck('component_item_id'))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => (string) $rows->sum('on_hand'));

        return $lines->map(function (BillOfMaterialLine $line) use ($factor, $onHand) {
            $base = bcmul(number_format((float) $line->quantity, 6, '.', ''), $factor, 10);

            // La merma es lo que se pierde en el proceso: si de cada 100 se
            // pierden 5, hay que emitir 105 para que queden 100. Sin esto la
            // orden cierra con una desviación sistemática que parece un error
            // y no lo es.
            $scrap = number_format((float) $line->scrap_percentage, 4, '.', '');
            $required = bcmul($base, bcadd('1', bcdiv($scrap, '100', 10), 10), 6);

            $available = $onHand[$line->component_item_id] ?? '0.000000';

            return [
                'component_item_id' => $line->component_item_id,
                'item_code' => $line->componentItem?->code,
                'item_name' => $line->componentItem?->name,
                'uom' => $line->componentItem?->unitOfMeasure?->code,
                'warehouse_id' => $line->warehouse_id,
                'quantity_per_batch' => number_format((float) $line->quantity, 6, '.', ''),
                'scrap_percentage' => $scrap,
                'required_quantity' => number_format((float) $required, 6, '.', ''),
                'on_hand' => number_format((float) $available, 6, '.', ''),
                // Para que la pantalla pueda avisar antes de emitir, en vez
                // de que el motor rechace la emisión a mitad de camino.
                'shortage' => bccomp($required, $available, 6) > 0
                    ? number_format((float) bcsub($required, $available, 6), 6, '.', '')
                    : '0.000000',
                'notes' => $line->notes,
            ];
        });
    }

    /**
     * LA GUARDA CENTRAL: que la receta no se contenga a sí misma.
     *
     * Directo (el producto entre sus propios componentes) o a cualquier
     * profundidad (A lleva B, B lleva C, C lleva A). Un ciclo no describe
     * nada fabricable, y sin esta comprobación cualquier recorrido en
     * profundidad —incluido el que haga un reporte futuro de explosión
     * multinivel— entraría en recursión infinita.
     *
     * Se comprueba al guardar y no al explotar: un dato imposible no debe
     * poder entrar, y descubrirlo recién al fabricar sería tarde.
     *
     * @param  int[]  $componentItemIds
     */
    public function assertNoCycle(Company $company, int $producedItemId, array $componentItemIds, ?int $ignoreBomId = null): void
    {
        if (in_array($producedItemId, $componentItemIds, true)) {
            $item = Item::find($producedItemId);

            throw new InvalidBillOfMaterialException(
                "El artículo {$item?->code} no puede ser componente de su propia receta."
            );
        }

        // Desde cada componente se busca si, bajando por las recetas, se
        // vuelve a llegar al producto. El conjunto de visitados evita repetir
        // trabajo y también protege de un ciclo preexistente en los datos.
        $visited = [];
        $pending = $componentItemIds;

        while ($pending !== []) {
            $current = array_pop($pending);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            $childIds = BillOfMaterialLine::query()
                ->join('bills_of_materials', 'bills_of_materials.id', '=', 'bill_of_material_lines.bill_of_material_id')
                ->where('bills_of_materials.company_id', $company->id)
                ->where('bills_of_materials.item_id', $current)
                ->when($ignoreBomId !== null, fn ($q) => $q->where('bills_of_materials.id', '!=', $ignoreBomId))
                ->pluck('bill_of_material_lines.component_item_id')
                ->all();

            foreach ($childIds as $childId) {
                if ($childId === $producedItemId) {
                    $producido = Item::find($producedItemId);
                    $intermedio = Item::find($current);

                    throw new InvalidBillOfMaterialException(
                        "La receta crearía un ciclo: {$producido?->code} lleva {$intermedio?->code}, ".
                        "y la receta de {$intermedio?->code} vuelve a llevar {$producido?->code}. ".
                        'Un producto no puede fabricarse a partir de sí mismo.'
                    );
                }

                $pending[] = $childId;
            }
        }
    }

    /**
     * La receta que se ofrece al fabricar un artículo: la predeterminada, o
     * la única activa si no hay ninguna marcada.
     */
    public function defaultFor(Company $company, int $itemId): ?BillOfMaterial
    {
        $active = BillOfMaterial::where('company_id', $company->id)
            ->where('item_id', $itemId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        // Con una sola receta activa no hace falta marcarla: exigirlo sería
        // burocracia para el caso normal, que es un producto con una fórmula.
        return $active->firstWhere('is_default', true) ?? ($active->count() === 1 ? $active->first() : null);
    }
}
