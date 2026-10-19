<?php

namespace App\Http\Controllers;

use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Http\Controllers\Concerns\HandlesCompanyBackups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administración → Respaldos (CLAUDE.md secc. 37): solo el Superusuario, con
 * las compañías de su licencia. Los Administradores no: es toda la
 * información de la compañía.
 */
class CompanyBackupController extends Controller
{
    use HandlesCompanyBackups;

    public function __construct(private readonly CompanyBackupService $backups) {}

    public function index(Request $request, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Backups/Index', $this->backupPageProps($this->ownLicense($request, $currentCompany), $currentCompany->id()));
    }

    public function preview(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        return $this->previewExport($request, $this->ownLicense($request, $currentCompany), $this->backups);
    }

    public function export(Request $request, CurrentCompany $currentCompany): StreamedResponse
    {
        return $this->downloadExport($request, $this->ownLicense($request, $currentCompany), $this->backups);
    }

    public function upload(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        return $this->uploadImport($request, $this->ownLicense($request, $currentCompany), $this->backups);
    }

    public function compare(Request $request, CurrentCompany $currentCompany, string $uuid): JsonResponse
    {
        return $this->compareImport($request, $this->ownLicense($request, $currentCompany), $uuid, $this->backups);
    }

    public function confirm(Request $request, CurrentCompany $currentCompany, string $uuid): JsonResponse
    {
        return $this->confirmImport($request, $this->ownLicense($request, $currentCompany), $uuid, $this->backups);
    }

    public function status(Request $request, CurrentCompany $currentCompany, string $uuid): JsonResponse
    {
        return $this->importStatus($this->ownLicense($request, $currentCompany), $uuid);
    }

    /** La licencia de la compañía activa, si quien pide es su Superusuario. */
    private function ownLicense(Request $request, CurrentCompany $currentCompany): License
    {
        $license = Company::find($currentCompany->id())?->license;

        abort_unless($license !== null && $license->superuser_id === $request->user()->id, 403, 'Los respaldos son solo del Superusuario.');

        return $license;
    }

    protected function backupActor(Request $request): array
    {
        return ['name' => $request->user()->name, 'email' => $request->user()->email, 'role' => 'Superusuario', 'user' => $request->user()];
    }

    protected function accountPasswordHash(Request $request): string
    {
        return (string) $request->user()->password;
    }
}
