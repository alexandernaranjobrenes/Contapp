<?php

namespace App\Domains\Banking\Models;

use App\Domains\Accounting\Models\JournalDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationLine extends Model
{
    protected $fillable = [
        'bank_reconciliation_id', 'journal_detail_id', 'matched_in_books', 'matched_in_bank', 'bank_statement_line_id',
    ];

    protected function casts(): array
    {
        return [
            'matched_in_books' => 'boolean',
            'matched_in_bank' => 'boolean',
        ];
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function journalDetail(): BelongsTo
    {
        return $this->belongsTo(JournalDetail::class);
    }

    public function bankStatementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class);
    }
}
