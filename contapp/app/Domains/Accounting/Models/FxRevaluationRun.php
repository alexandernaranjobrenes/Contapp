<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxRevaluationRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cutoff_date', 'exchange_rate_used', 'gain_account_id', 'loss_account_id', 'document_type_id',
        'journal_entry_id', 'status', 'executed_by', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'exchange_rate_used' => 'decimal:6',
            'executed_at' => 'datetime',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function gainAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gain_account_id');
    }

    public function lossAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'loss_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(FxRevaluationDetail::class);
    }
}
