<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia de un artículo en un almacén — la única fuente de verdad de la
 * cantidad. A propósito NO usa BelongsToCompany: no tiene company_id, su
 * aislamiento lo heredan item y warehouse, que sí lo tienen. Toda consulta
 * debe entrar por uno de esos dos (que sí traen CompanyScope), nunca
 * directo por esta tabla.
 */
class ItemWarehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'warehouse_id', 'on_hand', 'reserved', 'ordered', 'minimum_stock', 'maximum_stock',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
