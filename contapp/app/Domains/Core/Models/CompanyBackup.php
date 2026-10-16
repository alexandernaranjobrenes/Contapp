<?php

namespace App\Domains\Core\Models;

use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una importación de respaldo: subida y revisada, en cola, importando,
 * terminada o con error. Ver la migración y CLAUDE.md secc. 37.
 */
class CompanyBackup extends Model
{
    /** Lo que dura un respaldo subido y revisado esperando la confirmación. */
    public const HOURS_TO_CONFIRM = 24;

    protected $fillable = [
        'uuid', 'license_id', 'requested_by_user_id', 'requested_by_propietario_id', 'status',
        'file_path', 'secret', 'summary', 'company_data', 'result', 'progress', 'total',
        'company_id', 'error', 'expires_at', 'finished_at',
    ];

    protected $hidden = ['secret', 'file_path'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'summary' => 'array',
            'company_data' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'importing'], true);
    }

    /** Lo que ve la pantalla mientras importa y al terminar. */
    public function toStatus(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'progress' => $this->progress,
            'total' => $this->total,
            'company_id' => $this->company_id,
            'company_name' => $this->company_data['trade_name'] ?? $this->company_data['legal_name'] ?? null,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
