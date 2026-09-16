<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desglose de item_warehouses por ubicación. Nunca es fuente de verdad del
 * valor —el costo es global por artículo— solo de dónde está cada unidad.
 */
class ItemBin extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'warehouse_bin_id', 'on_hand',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
