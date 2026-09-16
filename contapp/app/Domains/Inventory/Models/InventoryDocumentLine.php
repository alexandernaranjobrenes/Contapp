<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sin BelongsToCompany, igual que ItemWarehouse: el aislamiento lo hereda de
 * su documento, que sí lo tiene.
 */
class InventoryDocumentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_document_id', 'line_number', 'item_id', 'warehouse_id', 'warehouse_bin_id',
        'to_warehouse_id', 'to_warehouse_bin_id',
        'quantity', 'unit_cost_local', 'unit_cost_foreign', 'description',
    ];

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
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
        return $this->hasMany(StockJournal::class, 'inventory_document_line_id');
    }
}
