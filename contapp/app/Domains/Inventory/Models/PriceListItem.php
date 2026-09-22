<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El precio de un artículo dentro de una lista.
 *
 * No lleva CompanyScope propio: cuelga de la lista, que sí lo tiene, igual
 * que las líneas de cualquier documento. Filtrar por compañía acá exigiría
 * una columna redundante que podría contradecir a la de su lista.
 */
class PriceListItem extends Model
{
    use HasFactory;

    protected $fillable = ['price_list_id', 'item_id', 'unit_price'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
