<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;
use App\Domains\Licensing\Models\License;
use App\Jobs\ImportCompanyBackupJob;
use App\Models\User;
use App\Notifications\CompanyBackupExportedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Lo que hacen las pantallas de respaldos (la del Superusuario y la del
 * backoffice): el resumen antes de exportar, la descarga, la subida y
 * revisión de un respaldo, y la importación en segundo plano.
 */
final class CompanyBackupService
{
    /** Lo que se cuenta en el resumen, por sección: lo que una persona reconoce. */
    private const HIGHLIGHTS = [
        'chart_of_accounts' => 'Cuentas contables',
        'journal_entries' => 'Asientos',
        'business_partners' => 'Socios de negocio',
        'bp_open_items' => 'Partidas abiertas',
        'bank_accounts' => 'Cuentas bancarias',
        'bank_reconciliations' => 'Conciliaciones bancarias',
        'items' => 'Artículos',
        'warehouses' => 'Almacenes',
        'inventory_documents' => 'Movimientos de inventario',
        'purchase_orders' => 'Órdenes de compra',
        'sales_documents' => 'Comprobantes',
        'sales_orders' => 'Órdenes de pedido',
        'employees' => 'Empleados',
        'payroll_periods' => 'Períodos de planilla',
        'labor_settlements' => 'Liquidaciones',
        'company_user' => 'Personas con acceso',
        'audit_logs' => 'Movimientos de la Bitácora',
    ];

    public function __construct(
        private readonly BackupExporter $exporter,
        private readonly BackupImporter $importer,
    ) {}

    /** El resumen antes de exportar: qué y cuánto. */
    public function exportSummary(BackupOptions $options): array
    {
        $counts = $this->exporter->counts($options);

        return [
            'company' => $options->company->trade_name ?: $options->company->legal_name,
            'period' => $options->describePeriod(),
            'opening_date' => $options->openingDate()?->format('d/m/Y'),
            'opening' => $counts['opening'],
            'sections' => $this->sectionSummary($options->sections, $counts['tables']),
            'total' => array_sum($counts['tables']),
        ];
    }

    /**
     * La descarga: el archivo se arma mientras baja, sin quedar guardado en
     * ningún lado del servidor.
     *
     * @param  array{name: string, email: string, role: string, user?: ?User, propietario_id?: ?int}  $by
     */
    public function download(BackupOptions $options, string $password, array $by): StreamedResponse
    {
        $company = $options->company;
        $name = 'respaldo-'.Str::slug($company->trade_name ?: $company->legal_name).'-'
            .($options->fromDate() ?? 'inicio').'-al-'.($options->toDate() ?? now($company->timezone ?: 'America/Costa_Rica')->format('Y-m-d')).'.txt';

        $this->recordExport($options, $by);

        return response()->streamDownload(function () use ($options, $password, $by, $company) {
            set_time_limit(0);
            $out = fopen('php://output', 'wb');

            BackupFile::write(
                $this->exporter->lines($options, ['name' => $by['name'], 'email' => $by['email'], 'role' => $by['role']]),
                $password,
                ['company' => trim(($company->trade_name ?: $company->legal_name).' '.($company->tax_id ? "({$company->tax_id})" : '')), 'period' => $options->describePeriod()],
                $out,
            );

            fclose($out);
        }, $name, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Sube y revisa un respaldo: lo descifra, verifica la firma y cuenta. No
     * importa nada todavía; queda esperando la confirmación.
     *
     * @throws InvalidBackupException
     */
    public function upload(UploadedFile $file, string $password, License $license, ?User $user, ?int $propietarioId): CompanyBackup
    {
        $uuid = (string) Str::uuid();
        $path = $file->storeAs('backups', "{$uuid}.txt", 'local');

        try {
            $inspection = $this->importer->inspect(Storage::disk('local')->path($path), $password);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e instanceof InvalidBackupException ? $e : new InvalidBackupException('El archivo no se pudo leer como un respaldo de CONTAPP.', previous: $e);
        }

        return CompanyBackup::create([
            'uuid' => $uuid,
            'license_id' => $license->id,
            'requested_by_user_id' => $user?->id,
            'requested_by_propietario_id' => $propietarioId,
            'status' => 'validated',
            'file_path' => $path,
            'secret' => $password,
            'summary' => $this->importSummary($inspection),
            'total' => $inspection['total'],
            'expires_at' => now()->addHours(CompanyBackup::HOURS_TO_CONFIRM),
        ]);
    }

    /**
     * Confirmado: a la cola. Importar puede tardar, y no hace falta tener la
     * pantalla abierta.
     *
     * @param  array{legal_name: string, trade_name?: ?string, tax_id?: ?string}  $companyData
     */
    public function confirm(CompanyBackup $backup, array $companyData, ?string $byName): void
    {
        $backup->update([
            'status' => 'queued',
            'company_data' => $companyData + ['by' => $byName],
            'error' => null,
        ]);

        ImportCompanyBackupJob::dispatch($backup->id);
    }

    /** Lo que se borra al terminar o al vencer: el archivo y la contraseña. */
    public static function forget(CompanyBackup $backup): void
    {
        if ($backup->file_path) {
            Storage::disk('local')->delete($backup->file_path);
        }

        $backup->forceFill(['file_path' => null, 'secret' => null])->save();
    }

    /** Lo que trae el respaldo, para mostrar antes de confirmar. */
    private function importSummary(array $inspection): array
    {
        $manifest = $inspection['manifest'];

        return [
            'company' => [
                'legal_name' => $manifest['compania']['legal_name'] ?? '',
                'trade_name' => $manifest['compania']['trade_name'] ?? null,
                'tax_id' => $manifest['compania']['tax_id'] ?? null,
            ],
            'period' => $manifest['periodo']['texto'] ?? '',
            'opening' => $manifest['saldos_iniciales'] ?? [],
            'opening_date' => isset($manifest['periodo']['desde']) ? date('d/m/Y', strtotime($manifest['periodo']['desde'].' -1 day')) : null,
            'sections' => $this->sectionSummary($manifest['secciones'] ?? [], $inspection['rows']),
            'exported' => $manifest['exportado'] ?? [],
            'people' => $inspection['people'],
            'total' => $inspection['total'],
        ];
    }

    /**
     * @param  list<string>  $sections
     * @param  array<string, int>  $rows
     * @return list<array{key: string, label: string, items: list<array{label: string, count: int}>}>
     */
    private function sectionSummary(array $sections, array $rows): array
    {
        $out = [];

        foreach ($sections as $section) {
            $items = [];

            foreach (self::HIGHLIGHTS as $table => $label) {
                if (BackupCatalog::section($table) === $section) {
                    $items[] = ['label' => $label, 'count' => (int) ($rows[$table] ?? 0)];
                }
            }

            $out[] = ['key' => $section, 'label' => BackupCatalog::SECTIONS[$section] ?? $section, 'items' => $items];
        }

        return $out;
    }

    /**
     * Queda en la Bitácora de la compañía. Si lo exportó el equipo de
     * CONTAPP, también se le avisa al Superusuario por correo: es su
     * información.
     */
    private function recordExport(BackupOptions $options, array $by): void
    {
        $company = $options->company;
        $values = ['period' => $options->describePeriod(), 'sections' => $options->sections];

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $by['user']?->id ?? null,
            'action' => 'company.backup_exported',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'new_values' => $values + (isset($by['propietario_id']) ? ['by' => "Equipo de CONTAPP ({$by['name']})"] : []),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);

        if (isset($by['propietario_id'])) {
            AuditLog::create([
                'propietario_id' => $by['propietario_id'],
                'action' => 'license_company_backup_exported',
                'auditable_type' => License::class,
                'auditable_id' => $company->license_id ?? 0,
                'new_values' => $values + ['company' => $company->trade_name ?: $company->legal_name],
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);

            $superuser = $company->license?->superuser_id ? User::find($company->license->superuser_id) : null;

            try {
                $superuser?->notify(new CompanyBackupExportedNotification($company, $options->describePeriod(), $by['name']));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
