<?php

namespace App\Console\Commands;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Core\Scopes\CompanyScope;
use App\Jobs\DeliverElectronicDocumentJob;
use App\Jobs\PollElectronicDocumentJob;
use App\Jobs\SubmitElectronicDocumentJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la cola tiene que hacer con los comprobantes electrónicos, cada
 * minuto (routes/console.php): enviar los firmados, reintentar los que no se
 * sabe si llegaron, consultar los que Hacienda procesa, y entregar al cliente
 * los aceptados.
 *
 * Es el único que programa trabajo a futuro: la máquina de estados deja en
 * next_attempt_at cuándo toca cada uno, y esto lo despacha cuando llega la
 * hora. Así un worker que se reinicia, o un trabajo que se pierde, no deja un
 * comprobante olvidado: al minuto siguiente se vuelve a despachar. Despachar
 * dos veces no hace daño (el servicio toma un candado por comprobante y
 * revisa el estado antes de actuar).
 */
class SyncHaciendaDocuments extends Command
{
    protected $signature = 'pivox:hacienda-sync {--limit=200 : cuántos comprobantes por corrida}';

    protected $description = 'Envía, consulta y entrega los comprobantes electrónicos pendientes ante Hacienda';

    public function handle(): int
    {
        // El código nuevo llega antes que sus migraciones (corren al reiniciar
        // el contenedor `app`), y el programador lo toma en el minuto
        // siguiente. Sin las tablas no hay nada que despachar: se sale en
        // silencio en vez de anotar el mismo error en el log cada minuto.
        if (! Schema::hasTable('electronic_documents')) {
            $this->warn('Faltan las tablas de facturación electrónica: corré las migraciones.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $due = fn ($query) => $query->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));

        $toSubmit = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->whereIn('status', [ElectronicDocument::SIGNED, ElectronicDocument::SUBMIT_UNKNOWN])
            ->where($due)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        // Uno que quedó «enviando» más de 10 minutos es de un proceso que se
        // cayó a mitad del envío: se reconcilia (se consulta antes de reenviar).
        $stuck = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('status', ElectronicDocument::SUBMITTING)
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $toPoll = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('status', ElectronicDocument::SUBMITTED)
            ->where($due)
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->pluck('id');

        $toDeliver = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('status', ElectronicDocument::ACCEPTED)
            ->where('delivery_status', 'pending')
            // Con media hora de margen: la entrega ya se despachó al aceptarse,
            // y esto es solo para la que se perdió.
            ->where('accepted_at', '<=', now()->subMinutes(30))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $toSubmit->merge($stuck)->unique()->each(fn (int $id) => SubmitElectronicDocumentJob::dispatch($id));
        $toPoll->each(fn (int $id) => PollElectronicDocumentJob::dispatch($id));
        $toDeliver->each(fn (int $id) => DeliverElectronicDocumentJob::dispatch($id));

        $this->info(sprintf(
            'Envíos: %d · Consultas: %d · Entregas: %d',
            $toSubmit->merge($stuck)->unique()->count(), $toPoll->count(), $toDeliver->count(),
        ));

        return self::SUCCESS;
    }
}
