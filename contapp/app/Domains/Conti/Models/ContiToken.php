<?php

namespace App\Domains\Conti\Models;

use App\Domains\Core\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El pase con el que el agente de n8n consulta la API de Conti a nombre de
 * una persona, en una compañía (ContiTokenService). Sin el CompanyScope: se
 * resuelve antes de que haya compañía activa, y es justamente lo que la fija.
 */
class ContiToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'company_id', 'token_hash', 'expires_at', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
