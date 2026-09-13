<?php

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialFollowUp extends Model
{
    protected $fillable = ['commercial_profile_id', 'next_action_date', 'action_type', 'status'];

    protected function casts(): array
    {
        return [
            'next_action_date' => 'date',
        ];
    }

    public function commercialProfile(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
