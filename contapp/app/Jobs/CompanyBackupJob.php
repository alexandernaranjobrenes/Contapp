<?php

namespace App\Jobs;

use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\CompanyBackup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lo que se hace con un respaldo subido, en la cola «backups» (CLAUDE.md
 * secc. 37; en Docker la atiende el servicio `queue`): revisarlo, compararlo
 * con una compañía que ya existe e importarlo. Con un archivo grande tarda, y
 * la pantalla no tiene que quedar abierta: sigue el avance.
 */
abstract class CompanyBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $backupId)
    {
        $this->onQueue('backups');
    }

    /** El estado en que lo espera; si está en otro, ya no hay nada que hacer. */
    abstract protected function expects(): string;

    /** El estado mientras corre. */
    abstract protected function running(): string;

    abstract protected function work(CompanyBackup $backup, string $path, BackupImporter $importer, CompanyBackupService $service): void;

    /** Si falla, cómo queda: el motivo ya es para la persona. */
    abstract protected function onError(CompanyBackup $backup, string $message): void;

    /** Lo que se le dice si falló algo que no es del respaldo. */
    abstract protected function unexpected(): string;

    /** Al terminar, bien o mal. */
    protected function after(CompanyBackup $backup): void {}

    public function handle(BackupImporter $importer, CompanyBackupService $service): void
    {
        $backup = CompanyBackup::with('license')->find($this->backupId);

        if ($backup === null || $backup->status !== $this->expects()) {
            return;
        }

        if ($this->running() !== $backup->status) {
            $backup->update(['status' => $this->running()]);
        }

        try {
            if ($backup->file_path === null || $backup->secret === null || ! Storage::disk('local')->exists($backup->file_path)) {
                throw new InvalidBackupException('El archivo del respaldo ya no está: volvé a subirlo.');
            }

            $this->work($backup, Storage::disk('local')->path($backup->file_path), $importer, $service);
        } catch (Throwable $e) {
            if (! $e instanceof InvalidBackupException) {
                report($e);
            }

            $this->onError($backup->refresh(), $e instanceof InvalidBackupException ? $e->getMessage() : $this->unexpected());
        } finally {
            $this->after($backup);
        }
    }
}
