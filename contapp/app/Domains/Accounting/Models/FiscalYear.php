<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'year', 'status'];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
