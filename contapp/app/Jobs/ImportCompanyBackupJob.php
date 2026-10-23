<?php

namespace App\Jobs;

use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\BackupProgress;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;
use App\Models\User;

/**
 * Importa un respaldo ya revisado y confirmado (CLAUDE.md secc. 37): en una
 * compañía nueva, o completando la que se comparó con lo que le falta.
 *
 * Todo o nada: si algo falla, no queda una compañía a medias ni una completada
 * a medias. Al terminar, bien o mal, se borran el archivo y su contraseña.
 */
class ImportCompanyBackupJob extends CompanyBackupJob
{
    protected function expects(): string
    {
        return 'queued';
    }

    protected function running(): string
    {
        return 'importing';
    }

    protected function work(CompanyBackup $backup, string $path, BackupImporter $importer, CompanyBackupService $service): void
    {
        $data = $backup->company_data;
        $by = $backup->requested_by_user_id ? User::find($backup->requested_by_user_id) : null;
        $manifest = ['compania' => $backup->summary['company'], 'periodo' => ['texto' => $backup->summary['period']]];
        $progress = BackupProgress::for($backup);

        if ($backup->mode === 'merge') {
            $company = Company::withoutGlobalScopes()->where('license_id', $backup->license_id)->find($backup->target_company_id);

            if ($company === null) {
                throw new InvalidBackupException('Esa compañía ya no está en la licencia.');
            }

            if (! $backup->license->isValid()) {
                throw new InvalidBackupException('La licencia no está vigente: no se le pueden agregar datos a sus compañías.');
            }

            $report = $importer->merge($path, (string) $backup->secret, $company, progress: $progress);
            BackupImporter::recordImport($company, $by, $data['by'] ?? null, $manifest, $report['totals']['added']);
            $this->recordForLicense($backup, $company, 'license_company_backup_merged');

            $backup->update([
                'status' => 'done',
                'company_id' => $company->id,
                'progress' => $backup->total,
                'result' => ['imported' => $report['totals']['added'], 'dropped' => [], 'people' => $report['people']],
                'comparison' => $service->mergeSummary($backup, $report),
                'finished_at' => now(),
            ]);

            return;
        }

        if (! $backup->license->canActivateAnotherCompany()) {
            throw new InvalidBackupException('La licencia ya no tiene cupo para otra compañía.');
        }

        $result = $importer->import(
            $path,
            (string) $backup->secret,
            $backup->license,
            ['legal_name' => $data['legal_name'], 'trade_name' => $data['trade_name'] ?? null, 'tax_id' => $data['tax_id'] ?? null],
            $progress,
        );

        BackupImporter::recordImport($result['company'], $by, $data['by'] ?? null, $manifest);
        $this->recordForLicense($backup, $result['company'], 'license_company_backup_imported');

        $backup->update([
            'status' => 'done',
            'company_id' => $result['company']->id,
            'progress' => $backup->total,
            'result' => [
                'imported' => array_sum($result['imported']),
                'dropped' => $result['dropped'],
                'people' => $result['people'],
            ],
            'finished_at' => now(),
        ]);
    }

    protected function onError(CompanyBackup $backup, string $message): void
    {
        $backup->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
    }

    protected function unexpected(): string
    {
        return 'No se pudo importar: ocurrió un error y no se guardó nada. Si se repite, avisale al equipo de Pivox.';
    }

    protected function after(CompanyBackup $backup): void
    {
        CompanyBackupService::forget($backup);
    }

    /** En el historial de la licencia, si lo hizo el equipo de Pivox. */
    private function recordForLicense(CompanyBackup $backup, Company $company, string $action): void
    {
        if (! $backup->requested_by_propietario_id) {
            return;
        }

        AuditLog::create([
            'propietario_id' => $backup->requested_by_propietario_id,
            'action' => $action,
            'auditable_type' => $backup->license->getMorphClass(),
            'auditable_id' => $backup->license_id,
            'new_values' => ['company' => $company->trade_name ?: $company->legal_name, 'period' => $backup->summary['period']],
            'created_at' => now(),
        ]);
    }
}
