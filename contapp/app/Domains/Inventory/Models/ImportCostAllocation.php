<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuánto de un rubro acumulado se cargó a una importación concreta. Es una
 * tabla y no una columna porque la relación es de muchos a muchos: un flete
 * se reparte entre varios contenedores, y una importación acumula flete,
 * aranceles y agencia.
 */
class ImportCostAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_cost_document_id', 'landed_cost_document_id', 'amount',
    ];

    public function importCost(): BelongsTo
    {
        return $this->belongsTo(ImportCostDocument::class, 'import_cost_document_id');
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCostDocument::class, 'landed_cost_document_id');
    }
}
