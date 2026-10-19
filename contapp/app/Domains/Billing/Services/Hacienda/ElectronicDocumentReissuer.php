<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\FiscalKeyGenerator;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Jobs\SubmitElectronicDocumentJob;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a emitir un comprobante que Hacienda rechazó (o que no recibió) con
 * un consecutivo, una clave y una fecha de emisión NUEVOS.
 *
 * Es la única corrección posible: una clave ya procesada no se puede reenviar
 * (guía, L16), y la nueva fecha evita el envío extemporáneo (L15) y mantiene
 * la clave igual a FechaEmision (L14).
 *
 * La venta no cambia —sus líneas, montos, asiento e inventario son los
 * mismos—: lo que se corrige es lo que causó el rechazo y vive fuera de ella
 * (los datos del emisor, la identificación o el correo del cliente). El XML se
 * arma de nuevo con esos datos actuales. Si lo que estaba mal son los montos,
 * no se reemite: se anula la venta con una nota de crédito interna y se emite
 * otra.
 */
class ElectronicDocumentReissuer
{
    public function __construct(
        private readonly ElectronicInvoicing $electronic,
        private readonly FiscalSequenceAllocator $sequences,
        private readonly FiscalKeyGenerator $keys,
        private readonly ElectronicDocumentIssuer $issuer,
    ) {}

    public function reissue(Company $company, SalesDocument $document, ?int $userId = null): ElectronicDocument
    {
        $electronic = DB::transaction(function () use ($company, $document, $userId) {
            $locked = SalesDocument::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $current = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
                ->where('sales_document_id', $locked->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($current === null || ! $current->canBeReissued()) {
                throw new ElectronicInvoicingException(
                    'Solo se vuelve a emitir un comprobante que Hacienda rechazó o no recibió'.
                    ($current ? " (este está «{$current->label()}»)." : '.')
                );
            }

            $settings = $this->electronic->settings($company);

            if (! $settings?->enabled) {
                throw new ElectronicInvoicingException('La facturación electrónica de la compañía está apagada.');
            }

            $missing = $this->electronic->missing($company);

            if ($missing !== []) {
                throw new ElectronicInvoicingException('La facturación electrónica está incompleta: '.implode(' ', $missing));
            }

            $issuedAt = HaciendaClock::now();
            $number = $this->sequences->next($company, $locked->branch, $locked->terminal, $locked->fiscal_document_type);
            $consecutive = $this->keys->consecutive($locked->branch, $locked->terminal, $locked->fiscal_document_type, $number);
            $securityCode = $this->keys->securityCode();

            $locked->update([
                'consecutive' => $consecutive,
                'security_code' => $securityCode,
                'issued_at' => $issuedAt,
                'clave' => $this->keys->clave($company, HaciendaClock::local($issuedAt), $consecutive, $locked->situation, $securityCode),
            ]);

            $new = $this->issuer->issue($company, $locked->fresh(), $settings, $userId);

            $current->transitionTo(ElectronicDocument::SUPERSEDED, "Reemplazado por el comprobante {$new->consecutive}, con clave nueva.", $userId, [
                'superseded_by_id' => $new->id,
                'next_attempt_at' => null,
            ]);

            return $new;
        });

        SubmitElectronicDocumentJob::dispatch($electronic->id)->afterCommit();

        return $electronic;
    }
}
