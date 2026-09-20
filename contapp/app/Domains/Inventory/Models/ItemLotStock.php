<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desglose por lote de item_warehouses (y de item_bins cuando el almacén usa
 * ubicaciones). Nunca es fuente de verdad del valor —el costo es global por
 * artículo— solo de cuánto hay de cada lote y dónde.
 */
class ItemLotStock extends Model
{
    use HasFactory;

    protected $table = 'item_lot_stock';

    protected $fillable = [
        'item_lot_id', 'warehouse_id', 'warehouse_bin_id', 'on_hand',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ItemLot::class, 'item_lot_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
