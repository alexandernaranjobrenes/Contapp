<?php

namespace App\Notifications;

use App\Domains\Pricing\Models\LicenseRequest;
use App\Domains\Pricing\Support\PricingMoney;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El aviso al backoffice de una solicitud de licencia nueva desde /precios
 * (CLAUDE.md secc. 42): quién la pide, el plan, la cotización y el enlace a
 * Solicitudes. Responder al correo le escribe a quien la pidió.
 *
 * No se encola: si falla, la solicitud igual queda guardada y se ve en el
 * backoffice (LicenseRequestService).
 */
class LicenseRequestReceivedNotification extends Notification
{
    public function __construct(private readonly LicenseRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quote = $this->request->quote;

        return (new MailMessage)
            ->subject("Nueva solicitud de licencia {$this->request->reference()}: {$this->request->company_name}")
            ->replyTo($this->request->email, $this->request->contact_name)
            ->markdown('mail.license-request-received', [
                'request' => $this->request,
                'lines' => LicenseRequestReplyNotification::lines($quote),
                'totals' => LicenseRequestReplyNotification::totals($quote),
                'cycle' => PricingMoney::cycle($this->request->billing_cycle),
                'url' => route('backoffice.license-requests.index', ['solicitud' => $this->request->id]),
            ]);
    }
}
