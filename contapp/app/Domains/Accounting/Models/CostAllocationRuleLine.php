<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostAllocationRuleLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'cost_allocation_rule_id', 'cost_center_id', 'percentage', 'position',
    ];

    public function costAllocationRule(): BelongsTo
    {
        return $this->belongsTo(CostAllocationRule::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
