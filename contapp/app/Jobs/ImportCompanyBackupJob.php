<?php

namespace App\Jobs;

use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\CompanyBackup;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Importa un respaldo ya revisado y confirmado (CLAUDE.md secc. 37). En la
 * cola «backups»: en Docker la atiende el servicio `queue`.
 *
 * Todo o nada: si algo falla, no queda una compañía a medias. Al terminar,
 * bien o mal, se borran el archivo y su contraseña.
 */
class ImportCompanyBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $backupId)
    {
        $this->onQueue('backups');
    }

    public function handle(BackupImporter $importer): void
    {
        $backup = CompanyBackup::with('license')->find($this->backupId);

        if ($backup === null || $backup->status !== 'queued') {
            return;
        }

        $backup->update(['status' => 'importing']);

        try {
            if (! $backup->license->canActivateAnotherCompany()) {
                throw new InvalidBackupException('La licencia ya no tiene cupo para otra compañía.');
            }

            if ($backup->file_path === null || $backup->secret === null || ! Storage::disk('local')->exists($backup->file_path)) {
                throw new InvalidBackupException('El archivo del respaldo ya no está: volvé a subirlo.');
            }

            $data = $backup->company_data;
            $result = $importer->import(
                Storage::disk('local')->path($backup->file_path),
                $backup->secret,
                $backup->license,
                ['legal_name' => $data['legal_name'], 'trade_name' => $data['trade_name'] ?? null, 'tax_id' => $data['tax_id'] ?? null],
            );

            BackupImporter::recordImport(
                $result['company'],
                $backup->requested_by_user_id ? User::find($backup->requested_by_user_id) : null,
                $data['by'] ?? null,
                ['compania' => $backup->summary['company'], 'periodo' => ['texto' => $backup->summary['period']]],
            );

            // En el historial de la licencia, si lo hizo el equipo de CONTAPP.
            if ($backup->requested_by_propietario_id) {
                AuditLog::create([
                    'propietario_id' => $backup->requested_by_propietario_id,
                    'action' => 'license_company_backup_imported',
                    'auditable_type' => $backup->license->getMorphClass(),
                    'auditable_id' => $backup->license_id,
                    'new_values' => ['company' => $result['company']->trade_name ?: $result['company']->legal_name, 'period' => $backup->summary['period']],
                    'created_at' => now(),
                ]);
            }

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
        } catch (Throwable $e) {
            if (! $e instanceof InvalidBackupException) {
                report($e);
            }

            $backup->update([
                'status' => 'failed',
                'error' => $e instanceof InvalidBackupException
                    ? $e->getMessage()
                    : 'No se pudo importar: ocurrió un error y no se guardó nada. Si se repite, avisale al equipo de CONTAPP.',
                'finished_at' => now(),
            ]);
        } finally {
            CompanyBackupService::forget($backup);
        }
    }
}
