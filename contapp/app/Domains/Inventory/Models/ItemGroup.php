<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agrupación de artículos. Además de clasificar el catálogo, es uno de los
 * niveles de la matriz de determinación de cuentas (Fase 2): item >
 * item_group > warehouse > company.
 */
class ItemGroup extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
