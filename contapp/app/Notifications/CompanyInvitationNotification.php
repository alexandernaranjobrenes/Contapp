<?php

namespace App\Notifications;

use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Services\CompanyInvitationService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La invitación a trabajar en una compañía: el enlace para aceptarla
 * (CompanyInvitationService). Dice quién invita, a qué compañía, con qué
 * rol, y si al aceptar se crea la cuenta o se usa la que ya existe.
 *
 * No se encola, por lo mismo que los demás correos con enlace: no hay worker
 * de colas.
 */
class CompanyInvitationNotification extends Notification
{
    public function __construct(
        private readonly CompanyInvitation $invitation,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->invitation->company;
        $companyName = $company->trade_name ?: $company->legal_name;

        return (new MailMessage)
            ->subject("Te invitaron a {$companyName} en ".config('app.name'))
            ->markdown('mail.company-invitation', [
                'inviter' => $this->invitation->inviter?->name,
                'company' => $companyName,
                'role' => $this->invitation->roleLabel(),
                'url' => route('company-invitation.show', $this->token),
                'days' => CompanyInvitationService::EXPIRES_IN_DAYS,
                'expiresAt' => $this->invitation->expires_at?->format('d/m/Y'),
                'hasAccount' => app(CompanyInvitationService::class)->accountFor($this->invitation->email) !== null,
                'email' => $this->invitation->email,
            ]);
    }
}
