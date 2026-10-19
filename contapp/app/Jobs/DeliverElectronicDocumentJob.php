<?php

namespace App\Jobs;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentDelivery;

/** Le envía al cliente el comprobante aceptado: XML firmado, respuesta de Hacienda y PDF. */
class DeliverElectronicDocumentJob extends ElectronicDocumentJob
{
    public int $timeout = 300;

    protected function work(ElectronicDocument $document): void
    {
        app(ElectronicDocumentDelivery::class)->deliver($document);
    }
}
