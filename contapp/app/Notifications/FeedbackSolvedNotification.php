<?php

namespace App\Notifications;

use App\Domains\Feedback\Models\FeedbackComment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El agradecimiento al autor de una publicación que el backoffice dio por
 * resuelta (FeedbackService::solve), con el mensaje del equipo si lo
 * escribió.
 *
 * No se encola: no hay worker de colas. Si el envío falla, el servicio lo
 * reporta y el backoffice lo ve en el mensaje de la pantalla.
 */
class FeedbackSolvedNotification extends Notification
{
    public function __construct(
        private readonly FeedbackComment $post,
        private readonly ?string $note = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('¡Gracias por tu aporte a '.config('app.name').'!')
            ->markdown('mail.feedback-solved', [
                'name' => $notifiable->name ?? null,
                'excerpt' => $this->post->excerpt(400),
                'note' => $this->note,
            ]);
    }
}
