<?php

namespace App\Notifications;

use App\Domains\Licensing\Models\LicenseInvitation;
use App\Domains\Licensing\Services\LicenseInvitationService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo que recibe la persona a la que el backoffice le asignó una
 * licencia: el enlace para aceptarla (LicenseInvitationService).
 *
 * No se encola, por lo mismo que los demás correos con enlace: no hay worker
 * de colas, y el enlace vence en media hora.
 */
class LicenseInvitationNotification extends Notification
{
    public function __construct(
        private readonly LicenseInvitation $invitation,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $license = $this->invitation->license;

        return (new MailMessage)
            ->subject('Tu licencia de '.config('app.name').': aceptala para empezar')
            ->markdown('mail.license-invitation', [
                'name' => $this->invitation->personName(),
                'url' => route('license-invitation.show', $this->token),
                'minutes' => LicenseInvitationService::EXPIRES_IN_MINUTES,
                'category' => $license->category?->name,
                'maxCompanies' => $license->max_companies,
                'expiresAt' => $license->expires_at?->format('d/m/Y'),
                'newAccount' => $this->invitation->account_type === LicenseInvitation::NEW,
                'requiresPassword' => $this->invitation->requiresPassword(),
                'email' => $this->invitation->email,
            ]);
    }
}
