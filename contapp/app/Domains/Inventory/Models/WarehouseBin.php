<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sin BelongsToCompany: el aislamiento lo hereda del almacén.
 */
class WarehouseBin extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id', 'code', 'name', 'status',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(ItemBin::class);
    }
}
