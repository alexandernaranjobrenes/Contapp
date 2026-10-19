<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Scopes\CompanyScope;
use App\Mail\ElectronicDocumentMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Entrega al cliente un comprobante aceptado. Solo uno aceptado: lo que
 * Hacienda todavía no aceptó (o rechazó) no tiene validez, y mandarlo haría
 * creer al cliente que tiene un comprobante que no existe.
 *
 * Si el correo falla, el comprobante sigue aceptado: queda anotado el motivo
 * y se puede reenviar desde su pantalla.
 */
class ElectronicDocumentDelivery
{
    /**
     * @param  list<string>|null  $recipients  otros correos (reenviar a mano); por defecto, el del cliente
     */
    public function deliver(ElectronicDocument $electronic, ?array $recipients = null): bool
    {
        if ($electronic->status !== ElectronicDocument::ACCEPTED || ! $electronic->sales_document_id) {
            return false;
        }

        // La entrega automática va una sola vez, aunque la cola la despache
        // dos (el programador vuelve a despachar las que se perdieron).
        // Reenviar a mano pasa los correos y siempre envía.
        if ($recipients === null && $electronic->fresh()->delivery_status === 'sent') {
            return true;
        }

        $recipients ??= $this->recipients($electronic);
        $recipients = array_values(array_unique(array_filter($recipients, [FiscalCatalogs::class, 'isValidEmail'])));

        if ($recipients === []) {
            $electronic->update([
                'delivery_status' => 'skipped',
                'delivery_error' => 'El cliente no tiene un correo válido: el comprobante no se envió.',
            ]);

            return false;
        }

        try {
            Mail::to($recipients)->send(new ElectronicDocumentMail($electronic->company_id, $electronic->id));
        } catch (Throwable $e) {
            report($e);

            $electronic->update([
                'delivery_status' => 'failed',
                'delivery_error' => mb_substr('No se pudo enviar el correo: '.$e->getMessage(), 0, 255),
            ]);

            return false;
        }

        $electronic->update([
            'delivery_status' => 'sent',
            'delivered_at' => now(),
            'delivery_error' => null,
            'delivery_recipients' => $recipients,
        ]);

        return true;
    }

    /** @return list<string> */
    private function recipients(ElectronicDocument $electronic): array
    {
        $document = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->with('businessPartner')
            ->find($electronic->sales_document_id);

        $email = trim((string) $document?->businessPartner?->email);

        return $email === '' ? [] : [$email];
    }
}
