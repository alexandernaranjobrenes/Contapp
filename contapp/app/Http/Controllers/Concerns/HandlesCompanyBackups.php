<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Core\Backup\BackupCatalog;
use App\Domains\Core\Backup\BackupFile;
use App\Domains\Core\Backup\BackupOptions;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El flujo de los respaldos (CLAUDE.md secc. 37), igual para el Superusuario
 * (su licencia) y para el backoffice (cualquiera): cada controlador dice de
 * qué licencia se trata y quién es.
 *
 * Exportar: elegir compañía, secciones y período → resumen → contraseña del
 * archivo y la de la cuenta → descarga.
 * Importar: subir el archivo con su contraseña → se revisa en la cola →
 * resumen (ya verificado) → una compañía nueva (sus datos) o completar una
 * que ya existe (se compara en la cola: qué le falta) → la contraseña de la
 * cuenta → en cola. La pantalla sigue el avance con importStatus().
 */
trait HandlesCompanyBackups
{
    /** @return array{name: string, email: string, role: string, user?: ?User, propietario_id?: ?int} */
    abstract protected function backupActor(Request $request): array;

    /** La contraseña de la cuenta de quien lo hace: exportar e importar es lo más sensible que hay. */
    abstract protected function accountPasswordHash(Request $request): string;

    /** Lo que la pantalla necesita para armar los formularios. */
    protected function backupPageProps(License $license, ?int $currentCompanyId = null): array
    {
        $companies = $this->licenseCompanies($license);

        return [
            'license' => [
                'id' => $license->id,
                'code' => $license->code,
                'companies' => $companies->count(),
                'max_companies' => $license->max_companies,
                'can_add_company' => $license->canActivateAnotherCompany(),
            ],
            // No «companies»: es el nombre de las del selector de la barra
            // superior (HandleInertiaRequests), y lo taparía.
            'licenseCompanies' => $companies->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->trade_name ?: $c->legal_name, 'tax_id' => $c->tax_id])->values(),
            'currentCompanyId' => $currentCompanyId,
            'sections' => collect(BackupCatalog::SECTIONS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'required' => in_array($key, BackupCatalog::REQUIRED_SECTIONS, true),
                'needs' => BackupCatalog::SECTION_REQUIRES[$key] ?? [],
            ])->values(),
            'minPassword' => BackupFile::MIN_PASSWORD,
            'imports' => CompanyBackup::with('company:id,legal_name,trade_name')
                ->where('license_id', $license->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (CompanyBackup $b) => [
                    'uuid' => $b->uuid,
                    'status' => $b->status,
                    'mode' => $b->mode,
                    'company_name' => $b->toStatus()['company_name'],
                    'error' => $b->error,
                    'source' => $b->summary['company']['trade_name'] ?? $b->summary['company']['legal_name'] ?? '',
                    'period' => $b->summary['period'] ?? '',
                    'created_at' => $b->created_at?->toIso8601String(),
                ]),
        ];
    }

    protected function previewExport(Request $request, License $license, CompanyBackupService $backups): JsonResponse
    {
        return response()->json($backups->exportSummary($this->backupOptions($request, $license)));
    }

    protected function downloadExport(Request $request, License $license, CompanyBackupService $backups): StreamedResponse
    {
        $options = $this->backupOptions($request, $license);
        $request->validate([
            'password' => ['required', 'string', 'min:'.BackupFile::MIN_PASSWORD, 'confirmed'],
        ], [
            'password.min' => 'La contraseña del archivo tiene que tener al menos '.BackupFile::MIN_PASSWORD.' caracteres.',
            'password.confirmed' => 'Las dos contraseñas del archivo no coinciden.',
        ]);
        $this->checkAccountPassword($request);

        return $backups->download($options, (string) $request->input('password'), $this->backupActor($request));
    }

    protected function uploadImport(Request $request, License $license, CompanyBackupService $backups): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:61440'],
            'password' => ['required', 'string'],
        ], [
            'file.max' => 'El archivo pesa más de 60 MB.',
        ]);

        $actor = $this->backupActor($request);

        try {
            $backup = $backups->upload($request->file('file'), (string) $request->input('password'), $license, $actor['user'] ?? null, $actor['propietario_id'] ?? null);
        } catch (InvalidBackupException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json($this->statusPayload($license, $backup));
    }

    /** Comparar con una compañía de la licencia: qué le falta. Va a la cola. */
    protected function compareImport(Request $request, License $license, string $uuid, CompanyBackupService $backups): JsonResponse
    {
        $backup = $this->pendingBackup($license, $uuid, ['validated', 'compared']);
        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::in($this->licenseCompanies($license)->pluck('id')->all())],
        ], [
            'company_id.required' => 'Elegí qué compañía completar.',
            'company_id.in' => 'Esa compañía no es de esta licencia.',
        ]);

        $backups->compare($backup, $this->licenseCompanies($license)->firstWhere('id', (int) $validated['company_id']));

        return response()->json($this->statusPayload($license, $backup->fresh()));
    }

    protected function confirmImport(Request $request, License $license, string $uuid, CompanyBackupService $backups): JsonResponse
    {
        $mode = $request->validate(['mode' => ['nullable', Rule::in(['new', 'merge'])]])['mode'] ?? 'new';
        $actor = $this->backupActor($request);
        $by = isset($actor['propietario_id']) ? "Equipo de Pivox ({$actor['name']})" : null;

        if ($mode === 'merge') {
            $backup = $this->pendingBackup($license, $uuid, ['compared']);
            $this->checkAccountPassword($request);

            // Lo mismo que dijo la comparación, en el servidor: la pantalla
            // ya no ofrece confirmar, pero eso no es una regla.
            if (($backup->comparison['conflicts_total'] ?? 0) > 0) {
                throw ValidationException::withMessages(['company_id' => 'Esta compañía no se puede completar con este respaldo: revisá lo que lo impide.']);
            }

            if (! $license->isValid()) {
                throw ValidationException::withMessages(['company_id' => 'La licencia no está vigente: no se le pueden agregar datos a sus compañías.']);
            }

            $backups->confirm($backup, null, $by);

            return response()->json($this->statusPayload($license, $backup->fresh()));
        }

        $backup = $this->pendingBackup($license, $uuid, ['validated', 'compared']);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ], ['legal_name.required' => 'Escribí la razón social de la compañía nueva.']);
        $this->checkAccountPassword($request);

        if (! $license->canActivateAnotherCompany()) {
            throw ValidationException::withMessages(['legal_name' => 'La licencia ya alcanzó el máximo de compañías: pedí más cupo antes de importar.']);
        }

        $backups->confirm($backup, $data, $by);

        return response()->json($this->statusPayload($license, $backup->fresh()));
    }

    protected function importStatus(License $license, string $uuid): JsonResponse
    {
        $backup = CompanyBackup::where('license_id', $license->id)->where('uuid', $uuid)->firstOrFail();

        return response()->json($this->statusPayload($license, $backup));
    }

    /**
     * Lo que la pantalla necesita de un respaldo subido: su estado y, ya
     * revisado, lo que trae y con qué datos se propone la compañía nueva.
     */
    private function statusPayload(License $license, CompanyBackup $backup): array
    {
        $payload = $backup->toStatus();

        if ($backup->summary === null || ! in_array($backup->status, ['validated', 'comparing', 'compared'], true)) {
            return $payload;
        }

        $company = $backup->summary['company'];

        // Si la licencia ya tiene una compañía con ese nombre (se restaura
        // mientras la original sigue ahí), se propone «(copia)»: dos iguales
        // en el selector de compañía se confunden.
        $taken = Company::withoutGlobalScopes()->where('license_id', $license->id)
            ->where(fn ($q) => $q->where('legal_name', $company['legal_name'])->orWhere('trade_name', $company['trade_name'] ?? $company['legal_name']))
            ->exists();
        $suffix = $taken ? ' (copia)' : '';

        return $payload + [
            'summary' => $backup->summary,
            'defaults' => [
                'legal_name' => $company['legal_name'].$suffix,
                'trade_name' => $company['trade_name'] ? $company['trade_name'].$suffix : null,
                'tax_id' => $company['tax_id'],
            ],
            'can_add_company' => $license->canActivateAnotherCompany(),
        ];
    }

    protected function backupOptions(Request $request, License $license): BackupOptions
    {
        $companies = $this->licenseCompanies($license);
        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::in($companies->pluck('id')->all())],
            'sections' => ['array'],
            'sections.*' => ['string', Rule::in(array_keys(BackupCatalog::SECTIONS))],
            'period' => ['required', Rule::in(['all', 'range'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,range'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'company_id.in' => 'Esa compañía no es de esta licencia.',
            'from.required_if' => 'Elegí desde qué fecha.',
            'to.after_or_equal' => '«Hasta» no puede ser antes de «Desde».',
        ]);

        $range = $validated['period'] === 'range';

        return new BackupOptions(
            $companies->firstWhere('id', (int) $validated['company_id']),
            $validated['sections'] ?? [],
            $range ? ($validated['from'] ?? null) : null,
            $range ? ($validated['to'] ?? null) : null,
        );
    }

    private function checkAccountPassword(Request $request): void
    {
        $request->validate(['account_password' => ['required', 'string']], ['account_password.required' => 'Escribí la contraseña de tu cuenta.']);

        if (! Hash::check((string) $request->input('account_password'), $this->accountPasswordHash($request))) {
            throw ValidationException::withMessages(['account_password' => 'La contraseña de tu cuenta no es correcta.']);
        }
    }

    /** @param  list<string>  $statuses  en los que puede estar para lo que se pide */
    private function pendingBackup(License $license, string $uuid, array $statuses): CompanyBackup
    {
        $backup = CompanyBackup::where('license_id', $license->id)->where('uuid', $uuid)->firstOrFail();

        if (! in_array($backup->status, $statuses, true) || $backup->expires_at?->isPast()) {
            throw ValidationException::withMessages(['legal_name' => 'Este respaldo ya no está esperando confirmación: volvé a subirlo.']);
        }

        return $backup;
    }

    /** @return Collection<int, Company> */
    private function licenseCompanies(License $license): Collection
    {
        return Company::withoutGlobalScopes()->where('license_id', $license->id)->orderBy('legal_name')->get();
    }
}
