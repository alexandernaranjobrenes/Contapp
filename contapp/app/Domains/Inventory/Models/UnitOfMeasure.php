<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Eloquent pluralizaría a "unit_of_measures"; el nombre correcto en
     * inglés es el que ya quedó en docs/modelo-datos.dbml.
     */
    protected $table = 'units_of_measure';

    protected $fillable = [
        'company_id', 'code', 'name', 'decimals', 'status',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'uom_id');
    }
}
