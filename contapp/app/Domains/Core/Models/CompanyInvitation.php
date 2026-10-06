<?php

namespace App\Domains\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La invitación a trabajar en una compañía, con un rol y permisos por
 * pantalla, que la persona acepta desde el correo (CompanyInvitationService).
 * Hasta entonces no entra: no hay acceso a la compañía, y si no tenía cuenta
 * en CONTAPP, tampoco cuenta.
 */
class CompanyInvitation extends Model
{
    protected $fillable = [
        'company_id', 'email', 'role_type', 'screen_permissions', 'invited_by',
        'token_hash', 'expires_at', 'sent_at', 'send_count', 'accepted_at', 'accepted_user_id',
        // Conti, si lo eligió el Superusuario al invitar (ContiAccessService).
        'conti_settings',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'screen_permissions' => 'array',
            'conti_settings' => 'array',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Las que todavía no se aceptaron, vencidas o no. */
    public function scopeNotAccepted(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    /** Las que se pueden aceptar: sin aceptar y sin vencer. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return ! $this->isAccepted() && ($this->expires_at === null || $this->expires_at->isPast());
    }

    /** 'accepted' | 'pending' | 'expired' */
    public function status(): string
    {
        return match (true) {
            $this->isAccepted() => 'accepted',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }

    public function roleLabel(): string
    {
        return $this->role_type === 'admin' ? 'Administrador' : 'Usuario';
    }
}
