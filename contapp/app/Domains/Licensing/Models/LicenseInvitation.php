<?php

namespace App\Domains\Licensing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una licencia asignada desde el backoffice a una persona, que la tiene que
 * aceptar desde el correo para que se active (LicenseInvitationService).
 *
 * Global, sin compañía: igual que License, existe antes de la compañía que
 * nace al aceptar.
 */
class LicenseInvitation extends Model
{
    public const EXISTING = 'existing';

    public const NEW = 'new';

    protected $fillable = [
        'license_id', 'account_type', 'user_id', 'name', 'email',
        'token_hash', 'expires_at', 'sent_at', 'send_count', 'accepted_at', 'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'send_count' => 'integer',
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

    /**
     * ¿Hay que elegir contraseña al aceptar? Siempre con una cuenta nueva
     * (la crea el backoffice, no la persona), y con una existente cuya
     * contraseña vigente la definió otra persona (users.password_chosen_at
     * nulo, ver LicenseActivationService::assertCanOwnLicense).
     */
    public function requiresPassword(): bool
    {
        return $this->account_type === self::NEW || $this->user?->password_chosen_at === null;
    }

    /** El nombre de la persona: el de su cuenta, o el que cargó el backoffice. */
    public function personName(): ?string
    {
        return $this->account_type === self::EXISTING ? $this->user?->name : $this->name;
    }
}
