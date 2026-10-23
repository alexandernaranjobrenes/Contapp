<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Licensing\Models\License;
use App\Http\Controllers\Concerns\HandlesCompanyBackups;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Respaldos desde el backoffice (CLAUDE.md secc. 37): el equipo de Pivox,
 * para cualquier compañía de una licencia. Si exporta, al Superusuario le
 * llega un correo.
 */
class CompanyBackupController extends Controller
{
    use HandlesCompanyBackups;

    public function __construct(private readonly CompanyBackupService $backups) {}

    public function index(int $license): Response
    {
        $license = License::findOrFail($license);

        return Inertia::render('Backoffice/Licenses/Backups', $this->backupPageProps($license) + [
            'superuser' => $license->superuser?->only(['name', 'email']),
        ]);
    }

    public function preview(Request $request, int $license): JsonResponse
    {
        return $this->previewExport($request, License::findOrFail($license), $this->backups);
    }

    public function export(Request $request, int $license): StreamedResponse
    {
        return $this->downloadExport($request, License::findOrFail($license), $this->backups);
    }

    public function upload(Request $request, int $license): JsonResponse
    {
        return $this->uploadImport($request, License::findOrFail($license), $this->backups);
    }

    public function compare(Request $request, int $license, string $uuid): JsonResponse
    {
        return $this->compareImport($request, License::findOrFail($license), $uuid, $this->backups);
    }

    public function confirm(Request $request, int $license, string $uuid): JsonResponse
    {
        return $this->confirmImport($request, License::findOrFail($license), $uuid, $this->backups);
    }

    public function status(int $license, string $uuid): JsonResponse
    {
        return $this->importStatus(License::findOrFail($license), $uuid);
    }

    protected function backupActor(Request $request): array
    {
        $propietario = Auth::guard('propietario')->user();

        return ['name' => $propietario->name, 'email' => $propietario->email, 'role' => 'Backoffice', 'propietario_id' => $propietario->id];
    }

    protected function accountPasswordHash(Request $request): string
    {
        return (string) Auth::guard('propietario')->user()->password;
    }
}
