<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'address', 'is_default', 'uses_bins', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'uses_bins' => 'boolean',
        ];
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(ItemWarehouse::class);
    }

    public function bins(): HasMany
    {
        return $this->hasMany(WarehouseBin::class);
    }
}
