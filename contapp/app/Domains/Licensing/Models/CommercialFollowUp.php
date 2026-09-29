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

    /**
     * next_action_date se muestra tal cual en el backoffice (listado de
     * licencias, perfil comercial): sin esto saldría con hora y zona. Mismo
     * criterio que License::serializeDate().
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
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
