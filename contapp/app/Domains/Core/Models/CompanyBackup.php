<?php

namespace App\Domains\Core\Models;

use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un respaldo subido para importar: se revisa en la cola (inspecting),
 * queda revisado (validated), se compara con una compañía que ya existe
 * (comparing → compared), y confirmado va a la cola (queued → importing →
 * done o failed). Sin confirmar, vence (expired) o se descarta (cancelled).
 * Ver las migraciones y CLAUDE.md secc. 37.
 */
class CompanyBackup extends Model
{
    /** Lo que dura un respaldo subido esperando la confirmación. */
    public const HOURS_TO_CONFIRM = 24;

    /** Mientras la cola trabaja con él. */
    public const RUNNING = ['inspecting', 'comparing', 'queued', 'importing'];

    /** Esperando que se confirme (o se descarte). */
    public const WAITING = ['validated', 'compared'];

    protected $fillable = [
        'uuid', 'license_id', 'requested_by_user_id', 'requested_by_propietario_id', 'status', 'mode', 'target_company_id',
        'file_path', 'secret', 'summary', 'company_data', 'comparison', 'result', 'progress', 'total',
        'company_id', 'error', 'expires_at', 'finished_at',
    ];

    protected $hidden = ['secret', 'file_path'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'summary' => 'array',
            'company_data' => 'array',
            'comparison' => 'array',
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

    public function targetCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'target_company_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /**
     * Se puede volver a él desde la lista: la cola trabaja con él (se sigue
     * su avance), o espera que se confirme y no venció.
     */
    public function canResume(): bool
    {
        return $this->isPending() || (in_array($this->status, self::WAITING, true) && $this->expires_at?->isFuture());
    }

    /** Lo que ve la pantalla mientras trabaja la cola y al terminar. */
    public function toStatus(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'mode' => $this->mode,
            'progress' => $this->progress,
            'total' => $this->total,
            'company_id' => $this->company_id,
            'target_company_id' => $this->target_company_id,
            'company_name' => $this->mode === 'merge'
                ? ($this->comparison['company']['name'] ?? null)
                : ($this->company_data['trade_name'] ?? $this->company_data['legal_name'] ?? null),
            'comparison' => $this->comparison,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
