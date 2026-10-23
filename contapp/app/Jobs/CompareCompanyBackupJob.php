<?php

namespace App\Jobs;

use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\BackupProgress;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;

/**
 * Compara un respaldo con una compañía que ya existe (BackupImporter::merge
 * sin guardar nada): qué le falta, qué ya tiene, qué tiene distinto y qué
 * impide completarla. Es lo que se muestra antes de confirmar.
 *
 * Si falla, el respaldo vuelve a quedar revisado, con el motivo: todavía se
 * puede comparar con otra o importar en una compañía nueva.
 */
class CompareCompanyBackupJob extends CompanyBackupJob
{
    protected function expects(): string
    {
        return 'comparing';
    }

    protected function running(): string
    {
        return 'comparing';
    }

    protected function work(CompanyBackup $backup, string $path, BackupImporter $importer, CompanyBackupService $service): void
    {
        $company = Company::withoutGlobalScopes()->where('license_id', $backup->license_id)->find($backup->target_company_id);

        if ($company === null) {
            throw new InvalidBackupException('Esa compañía ya no está en la licencia.');
        }

        $service->compared($backup, $importer->merge($path, (string) $backup->secret, $company, dryRun: true, progress: BackupProgress::for($backup)));
    }

    protected function onError(CompanyBackup $backup, string $message): void
    {
        $backup->update(['status' => 'validated', 'comparison' => null, 'error' => $message]);
    }

    protected function unexpected(): string
    {
        return 'No se pudo comparar: ocurrió un error. Si se repite, avisale al equipo de Pivox.';
    }
}
