<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No lleva company_id propio: se filtra a través de bank_account_id -> bank_accounts.company_id.
 */
class BankStatementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id', 'statement_date', 'description', 'amount', 'import_batch_id', 'matched',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'amount' => 'decimal:2',
            'matched' => 'boolean',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
