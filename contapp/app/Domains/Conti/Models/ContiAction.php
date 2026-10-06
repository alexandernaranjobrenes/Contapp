<?php

namespace App\Domains\Conti\Models;

use App\Domains\Core\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Algo que Conti preparó para guardar y que espera la confirmación de la
 * persona (ContiActionService). Se busca siempre por persona y compañía, sin
 * el CompanyScope: la API de Conti y la pantalla de confirmación llegan por
 * caminos distintos y cada uno lo filtra de forma explícita.
 */
class ContiAction extends Model
{
    public const STATUSES = [
        'pending' => 'Esperando tu confirmación',
        'confirmed' => 'Guardado',
        'discarded' => 'Descartado',
        'failed' => 'No se pudo guardar',
        'expired' => 'Venció sin confirmarse',
    ];

    protected $fillable = [
        'uuid', 'company_id', 'user_id', 'action', 'input', 'summary', 'payload_hash',
        'status', 'result', 'error', 'expires_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'summary' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
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

    /** El estado que importa: una pendiente que ya venció cuenta como vencida. */
    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }

    public function isActionable(): bool
    {
        return $this->effectiveStatus() === 'pending';
    }

    /** Una fecha y hora, en la zona horaria de la compañía (el servidor está en UTC). */
    public function localTime(?\DateTimeInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return Carbon::instance($moment)
            ->setTimezone($this->company?->timezone ?: config('app.timezone'))
            ->format('Y-m-d H:i');
    }
}
