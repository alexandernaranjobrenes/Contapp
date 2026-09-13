<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No lleva company_id propio: se filtra a través de open_item_id ->
 * bp_open_items -> business_partners.company_id.
 */
class BpPaymentApplication extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'payment_journal_entry_id', 'open_item_id', 'applied_amount', 'applied_date',
        'exchange_rate', 'realized_fx_difference', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'applied_date' => 'date',
            'applied_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'realized_fx_difference' => 'decimal:2',
        ];
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(BpOpenItem::class, 'open_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
