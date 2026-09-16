<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sin BelongsToCompany, igual que InventoryDocumentLine: el aislamiento lo
 * hereda de su documento.
 */
class LandedCostAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'landed_cost_document_id', 'item_id', 'warehouse_id',
        'received_quantity', 'on_hand_quantity',
        'allocated_amount', 'capitalized_amount', 'expensed_amount',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LandedCostDocument::class, 'landed_cost_document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockJournals(): HasMany
    {
        return $this->hasMany(StockJournal::class, 'landed_cost_allocation_id');
    }
}
