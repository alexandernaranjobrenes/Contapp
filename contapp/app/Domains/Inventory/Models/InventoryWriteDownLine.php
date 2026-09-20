<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWriteDownLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_write_down_id', 'line_number', 'item_id',
        'quantity', 'unit_cost_local', 'cost_value_local',
        'nrv_unit_local', 'nrv_value_local',
        'target_allowance_local', 'previous_allowance_local', 'movement_local',
        'reason',
    ];

    public function writeDown(): BelongsTo
    {
        return $this->belongsTo(InventoryWriteDown::class, 'inventory_write_down_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
