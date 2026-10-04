<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le avisa a una persona que le suspendieron, le devolvieron o le dieron de
 * baja el acceso a las compañías de una licencia (UserLifecycleService).
 *
 * No se encola: no hay worker de colas. Si el envío falla, el servicio lo
 * reporta y quien hizo el cambio lo ve en el mensaje de la pantalla.
 */
class MembershipStatusNotification extends Notification
{
    /**
     * @param  string  $status  'suspended' | 'active' | 'deactivated'
     * @param  list<string>  $companies  los nombres de las compañías afectadas
     */
    public function __construct(
        private readonly string $status,
        private readonly array $companies,
        private readonly ?string $changedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        $subject = match ($this->status) {
            'suspended' => "Tu acceso en {$app} quedó suspendido",
            'active' => "Ya podés volver a entrar a {$app}",
            default => "Se dio de baja tu acceso en {$app}",
        };

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.membership-status', [
                'name' => $notifiable->name ?? null,
                'status' => $this->status,
                'companies' => $this->companies,
                'changedBy' => $this->changedBy,
                'loginUrl' => route('login'),
            ]);
    }
}
