<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntrySchedule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'document_type_id', 'description', 'lines',
        'frequency_type', 'interval_count', 'next_run_date', 'expires_at',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'next_run_date' => 'date',
            'expires_at' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function generatedEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'schedule_id');
    }
}
