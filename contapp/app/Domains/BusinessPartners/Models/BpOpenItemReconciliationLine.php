<?php

namespace App\Domains\BusinessPartners\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpOpenItemReconciliationLine extends Model
{
    protected $fillable = ['bp_open_item_reconciliation_id', 'bp_open_item_id'];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BpOpenItemReconciliation::class, 'bp_open_item_reconciliation_id');
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(BpOpenItem::class, 'bp_open_item_id');
    }
}
