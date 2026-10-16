<?php

namespace App\Console\Commands;

use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Models\CompanyBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Un respaldo subido y revisado que nadie confirmó (CLAUDE.md secc. 37): a
 * las 24 horas se borran el archivo y su contraseña. Lo corre el
 * programador cada día.
 */
#[Signature('contapp:prune-backups')]
#[Description('Borra los respaldos subidos que no se confirmaron a tiempo')]
class PruneCompanyBackups extends Command
{
    public function handle(): int
    {
        $expired = CompanyBackup::where('status', 'validated')->where('expires_at', '<', now())->get();

        foreach ($expired as $backup) {
            CompanyBackupService::forget($backup);
            $backup->update(['status' => 'expired']);
        }

        $this->info("Respaldos vencidos: {$expired->count()}.");

        return self::SUCCESS;
    }
}
