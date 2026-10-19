<?php

namespace App\Mail;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentPdf;
use App\Domains\Billing\Services\Hacienda\ElectronicInvoicing;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El comprobante aceptado, para el cliente: el XML firmado (el documento que
 * vale), la respuesta de Hacienda que lo acepta y el PDF para leerlo. Las tres
 * piezas que la norma pide entregarle.
 *
 * Viajan los ids, como en PayslipMail: en la cola no hay compañía activa, y
 * los adjuntos se arman al enviar, no se guardan en la tabla de la cola.
 */
class ElectronicDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly int $companyId,
        private readonly int $electronicDocumentId,
    ) {}

    public function envelope(): Envelope
    {
        [$company, $electronic, $document] = $this->resolve();

        $emails = app(ElectronicInvoicing::class)->settings($company)?->emailList() ?? [];
        $type = FiscalCatalogs::DOCUMENT_TYPES[$electronic->document_type] ?? 'Comprobante electrónico';

        return new Envelope(
            subject: "{$type} {$electronic->consecutive} — ".($company->trade_name ?: $company->legal_name),
            replyTo: $emails === [] ? [] : [new Address($emails[0], $company->trade_name ?: $company->legal_name)],
        );
    }

    public function content(): Content
    {
        [$company, $electronic, $document] = $this->resolve();

        return new Content(
            markdown: 'mail.electronic-document',
            with: [
                'companyName' => $company->trade_name ?: $company->legal_name,
                'customerName' => $document->businessPartner?->name,
                'type' => FiscalCatalogs::DOCUMENT_TYPES[$electronic->document_type] ?? 'Comprobante electrónico',
                'consecutive' => $electronic->consecutive,
                'clave' => $electronic->clave,
                'total' => number_format((float) $document->total_document, 2, ',', '.'),
                'currency' => $document->currency?->code ?? 'CRC',
                'test' => $electronic->environment === 'stag',
            ],
        );
    }

    /** @return Attachment[] */
    public function attachments(): array
    {
        [$company, $electronic, $document] = $this->resolve();
        $pdf = app(ElectronicDocumentPdf::class);

        $attachments = [
            Attachment::fromData(fn () => $electronic->signed_xml, "{$electronic->clave}.xml")->withMime('application/xml'),
        ];

        if ($electronic->response_xml) {
            $attachments[] = Attachment::fromData(fn () => $electronic->response_xml, "{$electronic->clave}-respuesta.xml")
                ->withMime('application/xml');
        }

        $attachments[] = Attachment::fromData(fn () => $pdf->pdf($company, $document)->output(), $pdf->filename($document))
            ->withMime('application/pdf');

        return $attachments;
    }

    /** @return array{0: Company, 1: ElectronicDocument, 2: SalesDocument} */
    private function resolve(): array
    {
        $company = Company::findOrFail($this->companyId);

        $electronic = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOrFail($this->electronicDocumentId);

        $document = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOrFail($electronic->sales_document_id);

        return [$company, $electronic, $document];
    }
}
