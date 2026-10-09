<?php

namespace App\Console\Commands;

use App\Domains\Banking\Services\SyncBccrExchangeRatesService;
use App\Domains\Core\Services\ActivityRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('contapp:sync-bccr-rates {--date=}')]
#[Description('Sincroniza el tipo de cambio de referencia del BCCR (USD) para todas las compañías activas')]
class SyncBccrExchangeRates extends Command
{
    public function handle(SyncBccrExchangeRatesService $service, ActivityRecorder $activity): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        // En la Bitácora de cada compañía, a nombre de «Sistema».
        $results = $activity->asSystem(fn () => $service->syncForAllCompanies($date));

        $synced = count(array_filter($results));
        $skipped = count($results) - $synced;

        $this->info("BCCR: {$synced} compañía(s) sincronizada(s), {$skipped} sin dato para {$date->format('Y-m-d')}.");

        return self::SUCCESS;
    }
}
