<?php

namespace App\Jobs;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lo que la cola «hacienda» hace con un comprobante electrónico (CLAUDE.md
 * secc. 38; en Docker la atiende el servicio `queue-hacienda`).
 *
 * Viaja el id y no el modelo: en la cola no hay compañía activa, y el scope
 * de compañía —que falla cerrado— lo cargaría como inexistente. Se carga sin
 * el scope y se fija la compañía del comprobante mientras corre (el cliente,
 * la moneda y el resto se leen con ella), y se suelta al terminar: el worker
 * atiende comprobantes de todas las compañías.
 *
 * Un solo intento: los reintentos los decide la máquina de estados
 * (HaciendaSubmissionService), que sabe cuándo se puede reenviar y cuándo
 * no. El reintento automático de la cola reenviaría a ciegas.
 */
abstract class ElectronicDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $electronicDocumentId)
    {
        $this->onQueue(config('hacienda.queue', 'hacienda'));
    }

    abstract protected function work(ElectronicDocument $document): void;

    public function handle(CurrentCompany $currentCompany): void
    {
        $document = ElectronicDocument::withoutGlobalScope(CompanyScope::class)->find($this->electronicDocumentId);

        if ($document === null) {
            return;
        }

        $previous = $currentCompany->id();
        $currentCompany->set($document->company_id);

        try {
            $this->work($document);
        } finally {
            $previous === null ? $currentCompany->clear() : $currentCompany->set($previous);
        }
    }
}
