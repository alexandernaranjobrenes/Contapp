<?php

namespace App\Domains\Conti\Models;

use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que el Superusuario decidió para una persona de su licencia: si puede
 * usar Conti, sus límites y sus modelos (ContiAccessService).
 */
class ContiUserSetting extends Model
{
    protected $fillable = ['license_id', 'user_id', 'enabled', 'daily_credits', 'weekly_credits', 'models', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_credits' => 'decimal:2',
            'weekly_credits' => 'decimal:2',
            'models' => 'array',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
