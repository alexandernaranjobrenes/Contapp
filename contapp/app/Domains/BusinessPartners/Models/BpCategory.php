<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Inventory\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BpCategory extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'price_list_id'];

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'category_id');
    }

    /**
     * La lista que heredan los socios de esta categoría cuando no tienen una
     * propia. Nullable: una categoría sin lista no cambia nada y conserva su
     * uso original de agrupar reportes.
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * Por qué su código ya no se cambia (CodeRules), o null si todavía se
     * puede: con socios adentro, es el código por el que se agrupan sus
     * reportes y por el que Conti la busca.
     */
    public function codeLockReason(): ?string
    {
        return $this->businessPartners()->exists() ? 'ya hay socios en esta categoría' : null;
    }
}
