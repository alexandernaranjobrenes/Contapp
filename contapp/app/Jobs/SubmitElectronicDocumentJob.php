<?php

namespace App\Jobs;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Services\Hacienda\HaciendaSubmissionService;

/** Envía a Hacienda un comprobante firmado, o confirma que ya llegó. */
class SubmitElectronicDocumentJob extends ElectronicDocumentJob
{
    protected function work(ElectronicDocument $document): void
    {
        app(HaciendaSubmissionService::class)->submit($document);
    }
}
