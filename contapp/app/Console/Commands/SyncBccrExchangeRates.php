<?php

namespace App\Console\Commands;

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Exceptions\BccrUnavailableException;
use App\Domains\Banking\Services\SyncBccrExchangeRatesService;
use App\Domains\Banking\Support\BccrSyncStatus;
use App\Domains\Core\Services\ActivityRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * El tipo de cambio del BCCR para todas las compañías activas. Lo corre el
 * programador cada día (routes/console.php); también se puede correr a mano.
 *
 * - El día pedido (por defecto, hoy en Costa Rica: el día del BCCR) se trae
 *   o se actualiza, salvo una tasa bloqueada.
 * - Los días anteriores sin tasa (--catch-up, 7 por defecto) se completan:
 *   si el servidor estuvo apagado, esos días no se quedan con la tasa del
 *   último día que corrió. Una que ya existe no se toca.
 * - Cómo le fue queda en BccrSyncStatus, y se ve en Tipos de cambio.
 */
#[Signature('pivox:sync-bccr-rates {--date= : El día, AAAA-MM-DD (por defecto, hoy en Costa Rica)} {--catch-up=7 : Días anteriores sin tasa que se completan}')]
#[Description('Sincroniza el tipo de cambio de referencia del BCCR (USD) para todas las compañías activas')]
class SyncBccrExchangeRates extends Command
{
    public function handle(SyncBccrExchangeRatesService $service, ActivityRecorder $activity): int
    {
        $timezone = config('services.bccr.timezone');
        $date = $this->option('date') ? Carbon::parse($this->option('date'), $timezone) : Carbon::today($timezone);
        $catchUp = max(0, (int) $this->option('catch-up'));

        try {
            // En la Bitácora de cada compañía, a nombre de «Sistema».
            [$results, $filled] = $activity->asSystem(function () use ($service, $date, $catchUp) {
                $results = $service->syncForAllCompanies($date);
                $filled = 0;

                for ($day = 1; $day <= $catchUp; $day++) {
                    $filled += collect($service->syncForAllCompanies($date->copy()->subDays($day), onlyMissing: true))
                        ->filter(fn (?ExchangeRate $rate) => $rate?->wasRecentlyCreated)
                        ->count();
                }

                return [$results, $filled];
            });
        } catch (BccrUnavailableException $e) {
            // Lo que ven las compañías en Tipos de cambio, sin el detalle del
            // token; ese queda en la salida del comando.
            BccrSyncStatus::record(false, $date->format('Y-m-d'), $e->userMessage());
            $this->error("BCCR: {$e->getMessage()}");

            return self::FAILURE;
        }

        $synced = count(array_filter($results));
        $skipped = count($results) - $synced;
        $rate = collect($results)->filter()->first()?->rate;

        // Lo que ve cada compañía en Tipos de cambio: el tipo de cambio, no
        // cuántas compañías hay en Pivox. Eso queda en la salida del comando.
        $message = $synced > 0
            ? 'Tipo de cambio del '.$date->format('d/m/Y').($rate !== null ? ": {$rate}" : '').'.'
            : 'El BCCR todavía no tenía el tipo de cambio del '.$date->format('d/m/Y').'.';

        BccrSyncStatus::record($synced > 0, $date->format('Y-m-d'), $message);

        $this->info("BCCR: {$message} {$synced} compañía(s) al día."
            .($filled > 0 ? " Se completaron {$filled} tasa(s) de días anteriores que faltaban." : '')
            .($skipped > 0 ? " {$skipped} compañía(s) sin dato (otra moneda o sin publicar)." : ''));

        return self::SUCCESS;
    }
}
