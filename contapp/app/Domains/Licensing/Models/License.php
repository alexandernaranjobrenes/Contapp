<?php

namespace App\Domains\Licensing\Models;

use App\Domains\Core\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Global, sin company_id: una licencia existe ANTES de que exista la
 * compañía que activa (la compañía nace recién cuando alguien la canjea).
 */
class License extends Model
{
    use HasFactory;

    /**
     * Ventana de aviso "por vencer" (CLAUDE.md secc. 13, ej. 30/15/7 días
     * antes) — se usa el más amplio de los tres para el estado mostrado en
     * UI; las notificaciones automáticas en sí quedan fuera de esta entrega.
     */
    private const EXPIRING_SOON_THRESHOLD_DAYS = 30;

    protected $fillable = ['code', 'category_id', 'max_companies', 'max_admins', 'max_users', 'expires_at', 'status', 'notes', 'issued_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LicenseCategory::class, 'category_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'issued_by');
    }

    /**
     * El único dueño de la licencia (CLAUDE.md secc. 12) — se fija una sola
     * vez en LicenseActivationService::activate() y queda fuera de
     * $fillable a propósito, mismo criterio que User::is_super_admin.
     */
    public function superuser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superuser_id');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function commercialProfile(): HasOne
    {
        return $this->hasOne(CommercialProfile::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Suspendida o revocada: ambas son un corte administrativo deliberado
     * (disputa/impago o baja definitiva), a diferencia de un simple
     * vencimiento por fecha — SetCurrentCompany usa esto para decidir cuándo
     * sí corresponde cerrar la sesión de golpe en vez de dar modo de gracia.
     */
    public function isBlocked(): bool
    {
        return in_array($this->status, ['suspended', 'revoked'], true);
    }

    /**
     * Vigente para seguir operando (login) las compañías ya creadas bajo ella.
     */
    public function isValid(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    /**
     * Vencida pero no revocada: el caso que dispara el modo de gracia
     * (CLAUDE.md secc. 13) — nunca se corta el acceso de golpe solo por
     * vencimiento, a diferencia de una revocación (disputa/impago), que sí
     * sigue bloqueando por completo.
     */
    public function isExpiredButActive(): bool
    {
        return $this->status === 'active' && $this->isExpired();
    }

    /**
     * Referencia parcial/enmascarada para mostrar en UI (CLAUDE.md secc.
     * 13: "nunca mostrar el identificador completo si es también la clave
     * de validación"). CONTAPP-XXXX-XXXX-XXXX -> CONTAPP-****-****-XXXX.
     */
    public function maskedCode(): string
    {
        $parts = explode('-', $this->code);

        if (count($parts) !== 4) {
            return $this->code;
        }

        return "{$parts[0]}-****-****-{$parts[3]}";
    }

    /**
     * Vigente además de tener cupo para activar UNA compañía más.
     */
    public function canActivateAnotherCompany(): bool
    {
        return $this->isValid() && $this->companies()->count() < $this->max_companies;
    }

    /**
     * Administradores/Usuarios DISTINTOS bajo cualquiera de las compañías de
     * esta licencia — distinct() porque la misma persona puede tener
     * user_roles en varias compañías de la misma licencia y no debe
     * contarse dos veces. El Superusuario nunca se cuenta acá: no tiene fila
     * en user_roles (ver User::roleTypeFor()).
     */
    public function adminsCount(): int
    {
        return \App\Domains\Core\Models\UserRole::whereIn('company_id', $this->companies()->pluck('id'))
            ->whereHas('role', fn ($q) => $q->where('type', 'admin'))
            ->distinct('user_id')
            ->count('user_id');
    }

    public function usersCount(): int
    {
        return \App\Domains\Core\Models\UserRole::whereIn('company_id', $this->companies()->pluck('id'))
            ->whereHas('role', fn ($q) => $q->where('type', 'user'))
            ->distinct('user_id')
            ->count('user_id');
    }

    public function canAddAnotherAdmin(): bool
    {
        return $this->isValid() && $this->adminsCount() < $this->max_admins;
    }

    public function canAddAnotherUser(): bool
    {
        return $this->isValid() && $this->usersCount() < $this->max_users;
    }

    /**
     * Estado a mostrar en UI (CLAUDE.md secc. 13: activa/por vencer/vencida/
     * suspendida/revocada) — activa/por vencer/vencida son SIEMPRE
     * derivados de status+expires_at, nunca almacenados (evita que quede
     * una fila con un estado "vencida" ya obsoleto sin un cron que la
     * actualice); suspendida/revocada sí son decisiones administrativas
     * reales que solo se guardan explícitamente.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isSuspended() => 'suspended',
            $this->isExpired() => 'expired',
            now()->diffInDays($this->expires_at, false) <= self::EXPIRING_SOON_THRESHOLD_DAYS => 'expiring_soon',
            default => 'active',
        };
    }
}
