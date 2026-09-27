<?php

namespace App\Mail;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Services\PayslipDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El comprobante de pago enviado al trabajador, con el PDF adjunto.
 *
 * ── Por qué viaja el ID y no el modelo ───────────────────────────────────
 *
 * Porque esto se encola. Un Mailable en cola se serializa y se reconstruye en
 * otro proceso, donde NO hay compañía ambiental: el scope de compañía —que
 * falla cerrado— haría que la boleta se cargara como inexistente. Se pasan los
 * ids y se resuelven al construir el mensaje, con el filtro de compañía
 * explícito (ver PayslipDocument::findEntry).
 *
 * ── El PDF se genera al enviar, no antes ─────────────────────────────────
 *
 * Adjuntar los bytes en el constructor los metería en la tabla de la cola: un
 * comprobante de 60 KB por cada trabajador, y una planilla de cien personas
 * dejaría 6 MB de PDFs dentro de `jobs`. Se genera en el momento del envío,
 * desde la misma boleta que ya está guardada.
 */
class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly int $companyId,
        private readonly int $entryId,
    ) {}

    public function envelope(): Envelope
    {
        [$company, $entry] = $this->resolve();

        $period = $entry->period?->name ?? 'el período';

        return new Envelope(
            subject: "Comprobante de pago — {$period} — ".($company->trade_name ?: $company->legal_name),
        );
    }

    public function content(): Content
    {
        [$company, $entry] = $this->resolve();

        // Markdown y no una vista suelta: así hereda la plantilla de correo de
        // Laravel (`x-mail::message`), que ya resuelve el HTML que los clientes
        // de correo saben renderizar. Una vista propia obligaría a mantener a
        // mano una maqueta de tablas anidadas.
        return new Content(
            markdown: 'mail.payslip',
            with: [
                'companyName' => $company->trade_name ?: $company->legal_name,
                'employeeName' => $entry->employee?->fullName(),
                'periodName' => $entry->period?->name,
                'paymentDate' => $entry->period?->payment_date->format('d/m/Y'),
                'netPay' => $entry->net_pay,
            ],
        );
    }

    /** @return Attachment[] */
    public function attachments(): array
    {
        [$company, $entry] = $this->resolve();

        $document = app(PayslipDocument::class);

        return [
            Attachment::fromData(
                fn () => $document->pdf($company, $entry)->output(),
                $document->filename($entry),
            )->withMime('application/pdf'),
        ];
    }

    /**
     * @return array{0: Company, 1: PayrollEntry}
     */
    private function resolve(): array
    {
        $company = Company::findOrFail($this->companyId);
        $entry = app(PayslipDocument::class)->findEntry($company, $this->entryId);

        return [$company, $entry];
    }
}
