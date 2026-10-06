<?php

namespace App\Domains\Core\Services;

use App\Domains\Conti\Services\ContiAccessService;
use App\Domains\Core\Exceptions\CompanyInvitationException;
use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Models\Role;
use App\Domains\Licensing\Exceptions\LicenseQuotaExceededException;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invitar a una persona a trabajar en una compañía (Administración →
 * Usuarios → Crear nuevo). Quien invita pone el correo, el rol y los
 * permisos por pantalla; nada más.
 *
 * - Le llega un correo con un enlace. Hasta que lo acepta no entra a la
 *   compañía.
 * - Si el correo no tiene cuenta en CONTAPP, la cuenta se crea al aceptar,
 *   con el nombre y la contraseña que la persona elige. Quien invita nunca
 *   sabe ni pone la contraseña de nadie.
 * - Si ya tiene cuenta, al aceptar se le suma la compañía; su contraseña,
 *   su nombre y lo que tiene en otras compañías no cambian.
 * - El enlace vence a los EXPIRES_IN_DAYS días y se puede reenviar (el
 *   anterior deja de servir). Las mismas reglas de siempre: cada quien da
 *   hasta el acceso que tiene (PermissionGrantService), y el cupo de la
 *   licencia cuenta también las invitaciones sin aceptar.
 */
class CompanyInvitationService
{
    public const EXPIRES_IN_DAYS = 7;

    public function __construct(
        private readonly PermissionGrantService $grants,
        private readonly ContiAccessService $conti,
    ) {}

    /**
     * Crea la invitación y manda el correo. Si ya había una sin aceptar para
     * ese correo en esta compañía, la actualiza con el rol y los permisos
     * nuevos y la vuelve a mandar.
     *
     * @param  array<string, string>  $screenLevels  clave de pantalla => nivel
     * @param  array|null  $contiSettings  Conti, si lo eligió el Superusuario (ContiAccessService::normalize)
     * @return array{invitation: CompanyInvitation, mailed: bool}
     *
     * @throws CompanyInvitationException
     * @throws PrivilegeEscalationException
     * @throws LicenseQuotaExceededException
     */
    public function invite(User $grantor, Company $company, string $email, string $roleType, array $screenLevels, ?array $contiSettings = null): array
    {
        $email = Str::lower(trim($email));

        if (! in_array($roleType, $this->grants->grantableRoleTypes($grantor, $company->id), true)) {
            throw new CompanyInvitationException('No podés invitar a alguien con ese rol.', 'role_type');
        }

        if ($email === Str::lower($grantor->email)) {
            throw new CompanyInvitationException('Ese es tu propio correo.');
        }

        $levels = $this->grants->validateScreenLevels($grantor, $company->id, $screenLevels);
        $account = $this->accountFor($email);

        if ($account !== null) {
            $this->assertNotMember($company, $account);

            if ($account->status !== 'active') {
                throw new CompanyInvitationException('Esa cuenta de CONTAPP no está activa: no se la puede invitar.');
            }
        }

        $existing = CompanyInvitation::notAccepted()->where('company_id', $company->id)->where('email', $email)->first();

        $this->assertQuota($company, $roleType, $email, $account, $existing?->id);

        $invitation = $existing ?? new CompanyInvitation(['company_id' => $company->id, 'email' => $email]);
        $invitation->fill([
            'role_type' => $roleType,
            'screen_permissions' => array_filter($levels, fn (string $level) => $level !== 'none'),
            'conti_settings' => $contiSettings,
            'invited_by' => $grantor->id,
        ])->save();

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $grantor->id,
            'action' => 'user_invitation_sent',
            'auditable_type' => CompanyInvitation::class,
            'auditable_id' => $invitation->id,
            'new_values' => ['email' => $email, 'role_type' => $roleType, 'screens' => $invitation->screen_permissions, 'conti' => $contiSettings],
            'created_at' => now(),
        ]);

        return ['invitation' => $invitation, 'mailed' => $this->send($invitation)];
    }

    /**
     * Manda (o reenvía) el correo con un enlace nuevo: el anterior deja de
     * servir. Devuelve si el correo salió; si no, la invitación queda igual y
     * se puede reenviar.
     */
    public function send(CompanyInvitation $invitation): bool
    {
        if ($invitation->isAccepted()) {
            throw new CompanyInvitationException('Esa invitación ya se aceptó.');
        }

        $token = Str::random(64);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
            'sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new CompanyInvitationNotification($invitation->fresh(['company', 'inviter']), $token));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    public function cancel(CompanyInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw new CompanyInvitationException('Esa invitación ya se aceptó: la persona ya tiene acceso.');
        }

        $invitation->delete();
    }

    /** La invitación de ese enlace, aceptada o no. Null si el enlace no es de ninguna. */
    public function findByToken(string $token): ?CompanyInvitation
    {
        return CompanyInvitation::with(['company', 'inviter'])->where('token_hash', hash('sha256', $token))->first();
    }

    /** La cuenta de CONTAPP con ese correo, sin importar mayúsculas. */
    public function accountFor(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [Str::lower(trim($email))])->first();
    }

    /**
     * La persona acepta. Con cuenta, se le suma la compañía; sin cuenta, se
     * crea con $newAccount (nombre y contraseña, ya validados). $sessionUser:
     * quien tiene la sesión abierta, si alguien la tiene; tiene que ser la
     * cuenta invitada.
     *
     * Todo se vuelve a revisar al aceptar, no solo al invitar: el cupo de la
     * licencia, y que quien invitó todavía pueda dar ese rol y esos permisos.
     *
     * @param  array{name?: string, password?: string}  $newAccount
     *
     * @throws CompanyInvitationException
     */
    public function accept(CompanyInvitation $invitation, ?User $sessionUser, array $newAccount = []): User
    {
        return DB::transaction(function () use ($invitation, $sessionUser, $newAccount) {
            $invitation = CompanyInvitation::with(['company.license', 'inviter'])->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->isAccepted()) {
                throw new CompanyInvitationException('Esta invitación ya se aceptó.', 'invitation');
            }

            if ($invitation->isExpired()) {
                throw new CompanyInvitationException('Esta invitación venció. Pedile a quien te invitó que te la vuelva a enviar.', 'invitation');
            }

            $company = $invitation->company;
            $license = $company->license;

            if ($license !== null && $license->isBlocked()) {
                throw new CompanyInvitationException('La licencia de esta compañía no está activa: no se puede aceptar la invitación.', 'invitation');
            }

            $levels = $this->stillGrantable($invitation);
            $account = $this->accountFor($invitation->email);

            if ($account !== null) {
                if ($sessionUser !== null && $sessionUser->id !== $account->id) {
                    throw new CompanyInvitationException("Esta invitación es para {$invitation->email}, y entraste con otra cuenta. Cerrá sesión para aceptarla.", 'invitation');
                }

                if ($account->status !== 'active') {
                    throw new CompanyInvitationException('Tu cuenta de CONTAPP no está activa: no se puede aceptar la invitación.', 'invitation');
                }

                $this->assertNotMember($company, $account, 'invitation');
            } elseif ($sessionUser !== null) {
                throw new CompanyInvitationException("Esta invitación es para {$invitation->email}, y entraste con otra cuenta. Cerrá sesión para aceptarla.", 'invitation');
            }

            try {
                $this->assertQuota($company, $invitation->role_type, $invitation->email, $account, $invitation->id);
            } catch (LicenseQuotaExceededException) {
                throw new CompanyInvitationException('La licencia de esta compañía ya no tiene lugar para otra persona con ese rol. Avisale a quien te invitó.', 'invitation');
            }

            $isNew = $account === null;

            if ($isNew) {
                $account = User::create([
                    'name' => trim($newAccount['name'] ?? ''),
                    'email' => $invitation->email,
                    'password' => Hash::make($newAccount['password'] ?? Str::random(40)),
                    'default_company_id' => $company->id,
                    'status' => 'active',
                ]);

                // La contraseña la eligió la persona, y el enlace del correo
                // prueba que el correo es suyo.
                $account->forceFill(['password_chosen_at' => now(), 'email_verified_at' => now()])->save();
            }

            $company->users()->attach($account->id, [
                'is_default' => $isNew || $account->default_company_id === null,
                'status' => 'active',
            ]);

            if ($account->default_company_id === null) {
                $account->forceFill(['default_company_id' => $company->id])->save();
            }

            $account->userRoles()->firstOrCreate([
                'role_id' => $this->roleFor($invitation->role_type)->id,
                'company_id' => $company->id,
            ]);

            $this->grants->writeScreenLevels($company->id, $account, $levels);
            // Conti, como lo eligió el Superusuario al invitar.
            $this->conti->applyInvitation($invitation, $account);

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $account->id,
                'token_hash' => null,
            ])->save();

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $account->id,
                'action' => 'user_invitation_accepted',
                'auditable_type' => User::class,
                'auditable_id' => $account->id,
                'new_values' => [
                    'role_type' => $invitation->role_type,
                    'screens' => $invitation->screen_permissions,
                    'new_account' => $isNew,
                    'invited_by' => $invitation->invited_by,
                ],
                'created_at' => now(),
            ]);

            return $account;
        });
    }

    /**
     * Los permisos de la invitación, si quien invitó todavía los puede dar:
     * sigue en la compañía, con un rol que le permite invitar con ese rol, y
     * con al menos ese acceso en cada pantalla.
     *
     * @return array<string, string>
     */
    private function stillGrantable(CompanyInvitation $invitation): array
    {
        $inviter = $invitation->inviter;
        $companyId = $invitation->company_id;
        $invalid = new CompanyInvitationException('Esta invitación ya no es válida: cambiaron los permisos de quien te invitó. Pedile que te la vuelva a enviar.', 'invitation');

        if ($inviter === null || $inviter->status !== 'active') {
            throw $invalid;
        }

        $isMember = $inviter->companies()->wherePivot('status', 'active')->whereKey($companyId)->exists();

        if (! $isMember || ! in_array($invitation->role_type, $this->grants->grantableRoleTypes($inviter, $companyId), true)) {
            throw $invalid;
        }

        try {
            return $this->grants->validateScreenLevels($inviter, $companyId, $invitation->screen_permissions ?? []);
        } catch (PrivilegeEscalationException|\InvalidArgumentException) {
            throw $invalid;
        }
    }

    /** @throws CompanyInvitationException */
    private function assertNotMember(Company $company, User $account, string $field = 'email'): void
    {
        $membership = $company->users()->where('users.id', $account->id)->first();

        if ($membership === null) {
            return;
        }

        throw new CompanyInvitationException(match ($membership->pivot->status) {
            'active' => 'Esa persona ya tiene acceso a esta compañía.',
            'suspended' => 'Esa persona está suspendida en esta compañía: reactivala desde Usuarios.',
            default => 'Esa persona fue desactivada en esta compañía.',
        }, $field);
    }

    /**
     * El cupo de administradores o usuarios de la licencia, contando las
     * invitaciones sin aceptar (PermissionGrantService::assertSeatAvailable).
     *
     * @throws LicenseQuotaExceededException
     */
    private function assertQuota(Company $company, string $roleType, string $email, ?User $account, ?int $exceptInvitationId): void
    {
        $this->grants->assertSeatAvailable($company, $roleType, $account, $email, $exceptInvitationId);
    }

    private function roleFor(string $roleType): Role
    {
        return $this->grants->globalRole($roleType);
    }
}
