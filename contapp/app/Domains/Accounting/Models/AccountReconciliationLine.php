<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReconciliationLine extends Model
{
    protected $fillable = ['account_reconciliation_id', 'journal_detail_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function accountReconciliation(): BelongsTo
    {
        return $this->belongsTo(AccountReconciliation::class);
    }

    public function journalDetail(): BelongsTo
    {
        return $this->belongsTo(JournalDetail::class);
    }
}
