<?php

namespace App\Notifications;

use App\Domains\Feedback\Models\FeedbackComment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le avisa al autor que el backoffice quitó su publicación o su comentario
 * del canal de comentarios, y por qué (FeedbackService::remove).
 *
 * No se encola: no hay worker de colas. Si el envío falla, el servicio lo
 * reporta y el backoffice lo ve en el mensaje de la pantalla.
 */
class FeedbackRemovedNotification extends Notification
{
    public function __construct(private readonly FeedbackComment $comment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isPost = $this->comment->isPost();

        return (new MailMessage)
            ->subject(($isPost ? 'Quitamos tu publicación' : 'Quitamos tu comentario').' en '.config('app.name'))
            ->markdown('mail.feedback-removed', [
                'name' => $notifiable->name ?? null,
                'what' => $isPost ? 'tu publicación' : 'tu comentario',
                'reason' => $this->comment->removal_reason,
                'excerpt' => $this->comment->excerpt(400),
            ]);
    }
}
