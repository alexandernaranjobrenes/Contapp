<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La receta de un producto: qué lleva y cuánto. Nunca a qué costo — eso lo
 * pone el motor de movimientos cuando la emisión se contabiliza.
 */
class BillOfMaterial extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'bills_of_materials';

    protected $fillable = [
        'company_id', 'item_id', 'code', 'name', 'output_quantity',
        'is_default', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class);
    }
}
