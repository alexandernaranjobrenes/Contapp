<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * El correo con el enlace para elegir una contraseña nueva.
 *
 * Extiende la notificación de Laravel —que ya arma el enlace firmado con el
 * token— solo para decirlo en español y con la plantilla de correo del
 * proyecto (la misma `x-mail::message` del comprobante de pago).
 *
 * ── Por qué no se encola ─────────────────────────────────────────────────
 *
 * Un enlace que vence en una hora no puede quedar esperando a que alguien
 * levante un worker. Se envía en el momento; quien lo pide no espera al
 * servidor de correo porque PasswordResetLinkController lo manda después de
 * responder.
 */
class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Restablecé tu contraseña de '.config('app.name'))
            ->markdown('mail.reset-password', [
                'name' => $notifiable->name,
                'url' => $this->resetUrl($notifiable),
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]);
    }
}
