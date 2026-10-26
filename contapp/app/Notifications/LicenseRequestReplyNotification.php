<?php

namespace App\Notifications;

use App\Domains\Pricing\Models\LicenseRequest;
use App\Domains\Pricing\Support\PricingMoney;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La respuesta del backoffice a quien pidió una licencia (CLAUDE.md secc.
 * 42): el texto que escribió el equipo y, si se eligió, el resumen de la
 * cotización. Responder al correo le escribe al contacto de Pivox
 * (SITE_CONTACT_EMAIL).
 */
class LicenseRequestReplyNotification extends Notification
{
    public function __construct(
        private readonly LicenseRequest $request,
        private readonly string $subject,
        private readonly string $body,
        private readonly bool $includeQuote,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quote = $this->request->quote;
        $contact = config('site.contact_email');

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->markdown('mail.license-request-reply', [
                'request' => $this->request,
                // Párrafos separados por una línea en blanco, como los escribió el equipo.
                'paragraphs' => array_values(array_filter(array_map('trim', preg_split("/\R{2,}/", $this->body)))),
                'includeQuote' => $this->includeQuote,
                'lines' => self::lines($quote),
                'totals' => self::totals($quote),
                'cycle' => PricingMoney::cycle($this->request->billing_cycle),
                'contact' => $contact,
            ]);

        return $contact ? $mail->replyTo($contact, config('app.name')) : $mail;
    }

    /**
     * Las líneas de la cotización para una tabla de un correo. Un «|» en un
     * nombre rompería la tabla de Markdown.
     *
     * @return list<array{name: string, amount: string}>
     */
    public static function lines(array $quote): array
    {
        return array_map(fn (array $line) => [
            'name' => str_replace('|', '/', $line['kind'] === 'quantity'
                ? "{$line['name']} ({$line['quantity']} {$line['unit_label']})"
                : $line['name']),
            'amount' => PricingMoney::format($line['amount'], $quote['currency']),
        ], $quote['lines'] ?? []);
    }

    /** @return list<array{label: string, amount: string}> */
    public static function totals(array $quote): array
    {
        $currency = $quote['currency'];
        $rate = rtrim(rtrim(number_format((float) $quote['tax_rate'], 2, ',', '.'), '0'), ',');
        $rows = [['label' => 'Subtotal', 'amount' => PricingMoney::format($quote['subtotal'], $currency)]];

        if ($quote['discount'] > 0) {
            $rows[] = ['label' => 'Descuento del paquete', 'amount' => PricingMoney::format(-$quote['discount'], $currency)];
        }

        $rows[] = $quote['prices_include_tax']
            ? ['label' => "Incluye IVA ({$rate} %)", 'amount' => PricingMoney::format($quote['tax'], $currency)]
            : ['label' => "IVA ({$rate} %)", 'amount' => PricingMoney::format($quote['tax'], $currency)];

        $rows[] = ['label' => 'Total '.PricingMoney::cycle($quote['cycle']), 'amount' => PricingMoney::format($quote['total'], $currency)];

        return $rows;
    }
}
