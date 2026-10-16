<?php

namespace App\Notifications;

use App\Domains\Core\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le avisa al Superusuario que el equipo de CONTAPP exportó un respaldo de su
 * compañía (CLAUDE.md secc. 37): es su información, y tiene que saberlo.
 *
 * No se encola: el respaldo se exporta en el momento.
 */
class CompanyBackupExportedNotification extends Notification
{
    public function __construct(
        private readonly Company $company,
        private readonly string $period,
        private readonly string $byName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->company->trade_name ?: $this->company->legal_name;

        return (new MailMessage)
            ->subject("Se exportó un respaldo de {$company}")
            ->markdown('mail.company-backup-exported', [
                'name' => $notifiable->name ?? null,
                'company' => $company,
                'period' => $this->period,
                'by' => $this->byName,
                'at' => now($this->company->timezone ?: 'America/Costa_Rica')->format('d/m/Y H:i'),
            ]);
    }
}
