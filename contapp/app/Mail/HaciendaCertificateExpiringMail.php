<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Al Superusuario: la llave criptográfica de Hacienda de una de sus compañías
 * vence pronto. Con la llave vencida no se puede firmar, y sin firma no se
 * factura: se avisa a los 30, 15, 7 y 1 día.
 */
class HaciendaCertificateExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $environmentLabel,
        public readonly string $expiresOn,
        public readonly int $days,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "La llave de Hacienda de {$this->companyName} vence en {$this->days} ".($this->days === 1 ? 'día' : 'días'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.hacienda-certificate-expiring');
    }
}
