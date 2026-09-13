<?php

namespace App\Console\Commands;

use App\Domains\Accounting\Services\JournalEntryScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('contapp:process-journal-schedules {--date=}')]
#[Description('Genera los borradores pendientes de las programaciones de asientos ("Programable") cuya fecha ya llegó, para todas las compañías activas')]
class ProcessJournalEntrySchedules extends Command
{
    public function handle(JournalEntryScheduleService $service): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        $results = $service->processDueForAllCompanies($date);

        $generated = array_sum(array_map('count', $results));

        $this->info("Programaciones de asientos: {$generated} borrador(es) generado(s) para {$date->format('Y-m-d')}.");

        return self::SUCCESS;
    }
}
