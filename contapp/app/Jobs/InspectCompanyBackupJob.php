<?php

namespace App\Jobs;

use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\BackupProgress;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Models\CompanyBackup;

/**
 * Revisa un respaldo recién subido (BackupImporter::inspect): lo descifra
 * entero, verifica el SHA-256 y la firma, y cuenta. No guarda nada; queda
 * esperando que se elija qué hacer con él. Si no sirve, se borra.
 */
class InspectCompanyBackupJob extends CompanyBackupJob
{
    protected function expects(): string
    {
        return 'inspecting';
    }

    protected function running(): string
    {
        return 'inspecting';
    }

    protected function work(CompanyBackup $backup, string $path, BackupImporter $importer, CompanyBackupService $service): void
    {
        $service->inspected($backup, $importer->inspect($path, (string) $backup->secret, BackupProgress::for($backup)));
    }

    protected function onError(CompanyBackup $backup, string $message): void
    {
        $backup->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
        CompanyBackupService::forget($backup);
    }

    protected function unexpected(): string
    {
        return 'El archivo no se pudo leer como un respaldo de Pivox.';
    }
}
