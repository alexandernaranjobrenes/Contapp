<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un componente dentro de una receta. La cantidad es por LOTE (por
 * output_quantity unidades del producto), no por unidad: ver el encabezado
 * de la migración sobre por qué.
 *
 * No lleva company_id: cuelga de la receta, que sí lo tiene, igual que las
 * líneas de cualquier documento.
 */
class BillOfMaterialLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_of_material_id', 'component_item_id', 'quantity',
        'scrap_percentage', 'warehouse_id', 'notes',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
