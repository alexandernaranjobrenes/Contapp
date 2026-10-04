<?php

namespace App\Domains\Licensing\Services;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Exceptions\InvitationException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseInvitation;
use App\Models\User;
use App\Notifications\LicenseInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Asignar una licencia a una persona desde el backoffice, en vez de
 * entregarle el código para que la active ella.
 *
 * La licencia no se activa al asignarla: la persona recibe un correo con un
 * enlace que dura EXPIRES_IN_MINUTES, y recién al aceptarlo —con los datos
 * de su compañía— queda como Superusuario. Mientras tanto la licencia queda
 * reservada: su código no sirve en /activate (LicenseActivationService::
 * redeemable). Si el enlace vence, el backoffice reenvía el correo o cambia
 * los datos; las dos cosas generan un enlace nuevo y dejan sin efecto el
 * anterior.
 *
 * Dos clases de destinatario (CLAUDE.md secc. 12):
 *  - Una cuenta existente. Si su contraseña la eligió ella, acepta y listo.
 *    Si se la definió otra persona (users.password_chosen_at nulo), al
 *    aceptar tiene que elegir una nueva: sin eso no se activa.
 *  - Una cuenta nueva. El backoffice carga nombre y correo; la cuenta se
 *    crea al aceptar, con la contraseña que la persona elige en ese momento
 *    —siempre, porque una contraseña que puso otro es justo lo que no puede
 *    quedar en la cuenta dueña de una licencia—.
 *
 * Abrir el enlace prueba que la casilla es de quien acepta: por eso, al
 * aceptar, la contraseña elegida cuenta como propia (password_chosen_at).
 */
class LicenseInvitationService
{
    /** Minutos que dura el enlace del correo. */
    public const EXPIRES_IN_MINUTES = 30;

    public function __construct(private readonly LicenseActivationService $activation) {}

    /**
     * Asigna la licencia (o cambia a quién está asignada) y deja la
     * invitación lista para enviar con send(). No envía nada.
     *
     * @throws InvitationException
     */
    public function assign(License $license, string $accountType, string $email, ?string $name, ?int $propietarioId): LicenseInvitation
    {
        $this->assertAssignable($license);

        $email = trim($email);
        $invitation = LicenseInvitation::firstOrNew(['license_id' => $license->id]);

        if ($invitation->exists && $invitation->isAccepted()) {
            throw new InvitationException('La persona ya aceptó esta licencia: los datos ya no se pueden cambiar.');
        }

        $this->assertNoOtherInvitation($email, $license);

        if ($accountType === LicenseInvitation::EXISTING) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                throw new InvitationException('No hay ninguna cuenta con ese correo en CONTAPP. Si es una persona nueva, elegí «Cuenta nueva».', 'email');
            }

            if ($user->status !== 'active') {
                throw new InvitationException('Esa cuenta está inactiva y no puede ser dueña de una licencia.', 'email');
            }

            if ($owned = $this->activation->ownedLicense($user)) {
                throw new InvitationException("Esa cuenta ya es dueña de la licencia {$owned->maskedCode()}, y cada cuenta puede tener una sola.", 'email');
            }

            $invitation->fill([
                'account_type' => LicenseInvitation::EXISTING,
                'user_id' => $user->id,
                'name' => null,
                'email' => $user->email,
            ]);
        } else {
            if (User::where('email', $email)->exists()) {
                throw new InvitationException('Ese correo ya tiene una cuenta en CONTAPP. Elegí «Cuenta existente» para asignársela a esa cuenta.', 'email');
            }

            $invitation->fill([
                'account_type' => LicenseInvitation::NEW,
                'user_id' => null,
                'name' => trim((string) $name),
                'email' => $email,
            ]);
        }

        $invitation->created_by ??= $propietarioId;
        $invitation->save();

        return $invitation->setRelation('license', $license);
    }

    /**
     * Manda el correo con un enlace nuevo, que vence en EXPIRES_IN_MINUTES.
     * El enlace anterior, si lo había, deja de servir.
     *
     * @throws InvitationException
     */
    public function send(LicenseInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw new InvitationException('La persona ya aceptó esta licencia: no hay nada que reenviar.');
        }

        $this->assertAssignable($invitation->license);

        $token = Str::random(64);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        Notification::route('mail', $invitation->email)
            ->notify(new LicenseInvitationNotification($invitation->fresh(['license.category', 'user']), $token));
    }

    /**
     * Quita la asignación: la licencia vuelve a activarse con su código.
     *
     * @throws InvitationException
     */
    public function cancel(LicenseInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw new InvitationException('La persona ya aceptó esta licencia: la asignación no se puede quitar.');
        }

        $invitation->delete();
    }

    /** La invitación de ese enlace, aceptada o no. Null si el enlace no es de ninguna. */
    public function findByToken(string $token): ?LicenseInvitation
    {
        return LicenseInvitation::with(['license.category', 'user'])
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * La persona acepta: se activa la licencia a su nombre con su primera
     * compañía. Con una cuenta nueva, la cuenta se crea acá; con una
     * existente cuya contraseña la puso otro, se cambia acá.
     *
     * @param  array{legal_name: string, trade_name?: ?string, tax_id?: ?string}  $companyData
     * @return array{license: License, company: Company, user: User}
     *
     * @throws InvitationException
     * @throws InvalidLicenseException
     * @throws AccountNotEligibleException
     */
    public function accept(LicenseInvitation $invitation, array $companyData, ?string $password): array
    {
        return DB::transaction(function () use ($invitation, $companyData, $password) {
            // Dos pestañas aceptando a la vez: la segunda espera y encuentra
            // la invitación ya aceptada.
            $invitation = LicenseInvitation::with(['license', 'user'])->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->isAccepted()) {
                throw new InvitationException('Esta licencia ya se aceptó. Iniciá sesión con tu cuenta para entrar.');
            }

            if ($invitation->isExpired()) {
                throw new InvitationException('El enlace venció. Pedile al equipo de CONTAPP que te reenvíe el correo.');
            }

            if ($invitation->requiresPassword() && ($password === null || $password === '')) {
                throw new InvitationException('Elegí tu contraseña para aceptar la licencia.', 'password');
            }

            $license = $invitation->license;

            if ($invitation->account_type === LicenseInvitation::NEW) {
                if (User::where('email', $invitation->email)->exists()) {
                    throw new InvitationException('Mientras tanto, con ese correo se creó una cuenta en CONTAPP. Pedile al equipo de CONTAPP que te reenvíe la invitación para esa cuenta.');
                }

                $result = $this->activation->activate($license->code, $companyData, [
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'password' => $password,
                ], $invitation);
            } else {
                $user = User::lockForUpdate()->find($invitation->user_id);

                // El enlace llegó al correo que la cuenta tenía al asignarla.
                // Si la cuenta cambió de correo, ese enlace ya no prueba nada.
                if ($user === null || strcasecmp($user->email, $invitation->email) !== 0) {
                    throw new InvitationException('La cuenta cambió desde que se te asignó la licencia. Pedile al equipo de CONTAPP una invitación nueva.');
                }

                if ($invitation->requiresPassword()) {
                    // El enlace llegó a su casilla: la contraseña que elige
                    // ahora es suya. Ver password_chosen_at en
                    // LicenseActivationService::assertCanOwnLicense.
                    $user->forceFill([
                        'password' => $password,
                        'password_chosen_at' => now(),
                        'remember_token' => Str::random(60),
                    ])->save();
                }

                $result = $this->activation->activateForExistingUser($license->code, $companyData, $user, $invitation);
            }

            $invitation->forceFill(['accepted_at' => now(), 'token_hash' => null])->save();

            // En la bitácora de la licencia, junto a lo que hizo el backoffice:
            // quién la aceptó y con qué compañía nació.
            AuditLog::create([
                'company_id' => $result['company']->id,
                'user_id' => $result['user']->id,
                'action' => 'license_invitation_accepted',
                'auditable_type' => License::class,
                'auditable_id' => $license->id,
                'new_values' => [
                    'email' => $result['user']->email,
                    'account_type' => $invitation->account_type,
                    'company' => $result['company']->legal_name,
                ],
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * La licencia todavía se puede asignar: nadie la activó y está vigente.
     *
     * @throws InvitationException
     */
    private function assertAssignable(License $license): void
    {
        if ($license->superuser_id !== null || $license->companies()->exists()) {
            throw new InvitationException('Esta licencia ya está activada: ya tiene dueño.');
        }

        if (! $license->isValid()) {
            throw new InvitationException('Esta licencia está vencida, suspendida o revocada. Renovala o reactivala antes de asignarla.');
        }
    }

    /**
     * Una cuenta puede ser dueña de una sola licencia: tampoco puede tener
     * dos asignaciones esperando.
     *
     * @throws InvitationException
     */
    private function assertNoOtherInvitation(string $email, License $license): void
    {
        $other = LicenseInvitation::with('license')
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('license_id', '!=', $license->id)
            ->first();

        if ($other !== null) {
            throw new InvitationException("Ese correo ya tiene asignada la licencia {$other->license->maskedCode()}, esperando que la acepte.", 'email');
        }
    }
}
