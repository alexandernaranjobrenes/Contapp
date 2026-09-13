<?php

namespace App\Console\Commands;

use App\Domains\BusinessPartners\Services\OpenItemBackfillService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Reparación masiva: abre retroactivamente las partidas pendientes de TODOS
 * los socios de negocio de una compañía cuyas líneas ya contabilizadas nunca
 * marcaron "abre partida" en su momento — sin tocar ningún asiento original.
 * Ver App\Domains\BusinessPartners\Services\OpenItemBackfillService.
 */
class BackfillOpenItems extends Command
{
    protected $signature = 'open-items:backfill {company : ID de la compañía} {--dry-run : Solo mostrar qué se abriría, sin escribir nada}';

    protected $description = 'Abre retroactivamente las partidas pendientes faltantes de todos los socios de negocio de una compañía';

    public function handle(OpenItemBackfillService $service, CurrentCompany $currentCompany): int
    {
        $company = Company::find($this->argument('company'));

        if (! $company) {
            $this->error("No existe una compañía con id {$this->argument('company')}.");

            return self::FAILURE;
        }

        // Sin esto, el CompanyScope global de JournalEntry/BusinessPartner
        // (BelongsToCompany) falla cerrado por no tener compañía activa —
        // un comando de consola no pasa por el middleware que normalmente la
        // fija — y la reparación reportaría 0 en todos los casos, aunque sí
        // haya líneas por reparar.
        $currentCompany->set($company);

        $dryRun = (bool) $this->option('dry-run');

        $result = $service->run($company, $dryRun);

        foreach ($result['created'] as $line) {
            $prefix = $dryRun ? '[DRY-RUN] Se abriría' : 'Abierta';
            $this->info("{$prefix}: línea {$line['detail_id']} — {$line['partner_code']} ({$line['partner_name']}) — monto {$line['amount']} — vence {$line['due_date']}");
        }

        foreach ($result['skipped'] as $line) {
            $this->warn("Omitida: línea {$line['detail_id']} — {$line['partner_code']} — {$line['reason']}");
        }

        $this->newLine();
        $verb = $dryRun ? 'Se abrirían' : 'Se abrieron';
        $this->info("{$verb} ".count($result['created'])." partidas. Omitidas: ".count($result['skipped']).'.');

        return self::SUCCESS;
    }
}
