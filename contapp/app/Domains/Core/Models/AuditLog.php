<?php

namespace App\Domains\Core\Models;

use App\Domains\Licensing\Models\Propietario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una fila la hace un usuario de compañía (company_id + user_id) o el
 * Propietario (propietario_id, sin compañía ni usuario): los dos planos de
 * CLAUDE.md secc. 11 comparten la bitácora sin mezclar quién es quién.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'propietario_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
