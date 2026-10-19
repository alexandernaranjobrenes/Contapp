<?php

namespace App\Jobs;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Services\Hacienda\HaciendaSubmissionService;

/** Pregunta a Hacienda en qué quedó un comprobante enviado. */
class PollElectronicDocumentJob extends ElectronicDocumentJob
{
    protected function work(ElectronicDocument $document): void
    {
        app(HaciendaSubmissionService::class)->poll($document);
    }
}
