<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo que confirma un cambio de correo. Va a la casilla NUEVA —todavía
 * no es la de la cuenta—, con un enlace firmado: el cambio se aplica recién
 * cuando alguien lo abre, y eso prueba que la casilla es de quien lo pidió
 * (ver ProfileController::requestEmailChange).
 *
 * No se encola, por lo mismo que ResetPasswordNotification: no hay worker de
 * colas, y el enlace vence.
 */
class ConfirmEmailChangeNotification extends Notification
{
    public function __construct(
        private readonly string $url,
        private readonly string $name,
        private readonly int $minutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmá tu correo nuevo de '.config('app.name'))
            ->markdown('mail.confirm-email-change', [
                'name' => $this->name,
                'url' => $this->url,
                'minutes' => $this->minutes,
            ]);
    }
}
