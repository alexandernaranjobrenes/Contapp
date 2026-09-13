<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BpCategory extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'code', 'name'];

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'category_id');
    }
}
