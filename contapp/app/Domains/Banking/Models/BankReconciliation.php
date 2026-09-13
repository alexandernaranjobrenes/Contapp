<?php

namespace App\Domains\Banking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No lleva company_id propio: se filtra a través de bank_account_id -> bank_accounts.company_id.
 */
class BankReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id', 'cutoff_date', 'bank_balance', 'book_balance',
        'unrecorded_deposits', 'unpaid_checks', 'unrecorded_bank_credits', 'unrecorded_bank_debits',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'bank_balance' => 'decimal:2',
            'book_balance' => 'decimal:2',
            'unrecorded_deposits' => 'decimal:2',
            'unpaid_checks' => 'decimal:2',
            'unrecorded_bank_credits' => 'decimal:2',
            'unrecorded_bank_debits' => 'decimal:2',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function adjustedBookBalance(): string
    {
        return bcsub(
            bcadd((string) $this->book_balance, (string) $this->unrecorded_bank_credits, 2),
            (string) $this->unrecorded_bank_debits,
            2
        );
    }

    public function adjustedBankBalance(): string
    {
        return bcsub(
            bcadd((string) $this->bank_balance, (string) $this->unrecorded_deposits, 2),
            (string) $this->unpaid_checks,
            2
        );
    }

    public function isBalanced(): bool
    {
        return bccomp($this->adjustedBookBalance(), $this->adjustedBankBalance(), 2) === 0;
    }
}
