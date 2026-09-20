<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemLot;
use Illuminate\Support\Collection;

/**
 * Regla única de lotes, compartida por el motor de movimientos, el de
 * traslados y el de producción. Vive aparte por la misma razón que
 * WarehouseBinResolver: si cada vía tuviera su copia, una podría aceptar un
 * lote que la otra rechaza y la trazabilidad quedaría con huecos que nadie
 * ve hasta que hay que hacer un recall.
 */
class ItemLotResolver
{
    /**
     * Operaciones que EXIGEN un lote despachable (activo y no vencido).
     *
     * Son las que entregan la mercancía a un cliente o la incorporan a un
     * producto: ahí un lote vencido o retenido por calidad no debe pasar
     * nunca.
     *
     * Las demás salidas —salida de mercancía, ajuste por conteo, traslado—
     * SÍ admiten lote vencido o bloqueado, y es deliberado: son justamente
     * las vías por las que se da de baja o se aísla ese lote. Bloquearlas
     * dejaría la mercancía vencida atrapada en el inventario, sin forma de
     * sacarla del sistema ni de moverla a cuarentena — el sistema estaría
     * obligando a mantener como activo algo que hay que destruir.
     */
    public const RESTRICTED_OPERATIONS = ['sales_issue', 'production_issue'];

    /**
     * @param  Collection<int, ItemLot>  $lots  indexada por id
     */
    public function resolve(
        Item $item,
        ?int $lotId,
        Collection $lots,
        string $operation,
        \DateTimeInterface $postingDate,
    ): ?ItemLot {
        if (! $item->tracks_lots) {
            if ($lotId !== null) {
                throw new InvalidStockMovementException(
                    "El artículo {$item->code} no maneja lotes; la línea no debe indicar uno."
                );
            }

            return null;
        }

        if ($lotId === null) {
            throw new InvalidStockMovementException(
                "El artículo {$item->code} maneja lotes; cada línea debe indicar en cuál."
            );
        }

        $lot = $lots->get($lotId);

        if (! $lot || $lot->item_id !== $item->id) {
            throw new InvalidStockMovementException(
                "El lote id {$lotId} no pertenece al artículo {$item->code}."
            );
        }

        if (! in_array($operation, self::RESTRICTED_OPERATIONS, true)) {
            return $lot;
        }

        if ($lot->status !== 'active') {
            throw new InvalidStockMovementException(
                "El lote {$lot->code} de {$item->code} está retenido; no se puede despachar ni consumir. ".
                'Para darlo de baja o moverlo a cuarentena usá una salida de mercancía o un traslado.'
            );
        }

        if ($lot->isExpiredOn($postingDate)) {
            throw new InvalidStockMovementException(
                "El lote {$lot->code} de {$item->code} venció el {$lot->expires_at->format('Y-m-d')} ".
                "y el movimiento se contabiliza al {$postingDate->format('Y-m-d')}; no se puede despachar ni consumir. ".
                'Para darlo de baja usá una salida de mercancía.'
            );
        }

        return $lot;
    }

    /**
     * Sugerencia FEFO: en qué orden conviene tomar los lotes con saldo de un
     * artículo en un almacén (y, si el almacén las usa, en una ubicación).
     *
     * Es una SUGERENCIA para la pantalla, no una imposición del motor. Quien
     * digita puede elegir otro lote —hay razones legítimas: un cliente pide
     * un lote específico, una orden de producción exige continuidad de
     * lote— y el motor solo exige que el que elija sea despachable. Imponer
     * FEFO en el service convertiría una buena práctica de rotación en una
     * regla contable, que no lo es.
     *
     * @return Collection<int, array{lot: ItemLot, on_hand: string}>
     */
    public function suggestForIssue(
        Item $item,
        int $warehouseId,
        ?int $warehouseBinId,
        \DateTimeInterface $postingDate,
    ): Collection {
        if (! $item->tracks_lots) {
            return collect();
        }

        return ItemLot::query()
            ->where('item_id', $item->id)
            ->where('status', 'active')
            ->whereHas('stockLevels', function ($query) use ($warehouseId, $warehouseBinId) {
                $query->where('warehouse_id', $warehouseId)
                    ->where('on_hand', '>', 0)
                    ->when($warehouseBinId !== null, fn ($q) => $q->where('warehouse_bin_id', $warehouseBinId));
            })
            ->with(['stockLevels' => function ($query) use ($warehouseId, $warehouseBinId) {
                $query->where('warehouse_id', $warehouseId)
                    ->when($warehouseBinId !== null, fn ($q) => $q->where('warehouse_bin_id', $warehouseBinId));
            }])
            ->fefo()
            ->get()
            ->reject(fn (ItemLot $lot) => $lot->isExpiredOn($postingDate))
            ->map(fn (ItemLot $lot) => [
                'lot' => $lot,
                'on_hand' => (string) $lot->stockLevels->sum('on_hand'),
            ])
            ->values();
    }
}
