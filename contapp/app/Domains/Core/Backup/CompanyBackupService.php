<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;
use App\Domains\Licensing\Models\License;
use App\Jobs\CompareCompanyBackupJob;
use App\Jobs\ImportCompanyBackupJob;
use App\Jobs\InspectCompanyBackupJob;
use App\Models\User;
use App\Notifications\CompanyBackupExportedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Lo que hacen las pantallas de respaldos (la del Superusuario y la del
 * backoffice): el resumen antes de exportar, la descarga, la subida de un
 * respaldo, y en segundo plano su revisión, la comparación con una compañía
 * que ya existe y la importación.
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

    public function __construct(private readonly BackupExporter $exporter) {}

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
     * Queda en la Bitácora (y se le avisa al Superusuario) cuando salió
     * entero: si la descarga se corta o falla a la mitad, no se exportó nada.
     *
     * @param  array{name: string, email: string, role: string, user?: ?User, propietario_id?: ?int}  $by
     */
    public function download(BackupOptions $options, string $password, array $by): StreamedResponse
    {
        $company = $options->company;
        $name = 'respaldo-'.Str::slug($company->trade_name ?: $company->legal_name).'-'
            .($options->fromDate() ?? 'inicio').'-al-'.($options->toDate() ?? now($company->timezone ?: 'America/Costa_Rica')->format('Y-m-d')).'.txt';
        $ip = request()->ip();

        return response()->streamDownload(function () use ($options, $password, $by, $company, $ip) {
            set_time_limit(0);
            $out = fopen('php://output', 'wb');

            BackupFile::write(
                $this->exporter->lines($options, ['name' => $by['name'], 'email' => $by['email'], 'role' => $by['role']]),
                $password,
                ['company' => trim(($company->trade_name ?: $company->legal_name).' '.($company->tax_id ? "({$company->tax_id})" : '')), 'period' => $options->describePeriod()],
                $out,
            );

            fclose($out);

            // Que lo último le llegue a quien descarga antes de anotarlo: si
            // ya se fue, PHP corta acá y no queda como exportado.
            flush();

            if (! connection_aborted()) {
                $this->recordExport($options, $by, $ip);
            }
        }, $name, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Sube un respaldo. Acá solo se comprueba que sea un respaldo y que la
     * contraseña sea la suya (el primer tramo): la revisión completa —todo el
     * archivo, el SHA-256 y la firma— va en la cola, porque con años de
     * historia tarda, y la pantalla sigue su avance.
     *
     * @throws InvalidBackupException
     */
    public function upload(UploadedFile $file, string $password, License $license, ?User $user, ?int $propietarioId): CompanyBackup
    {
        $uuid = (string) Str::uuid();
        $path = $file->storeAs('backups', "{$uuid}.txt", 'local');

        try {
            BackupFile::check(Storage::disk('local')->path($path), $password);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e instanceof InvalidBackupException ? $e : new InvalidBackupException('El archivo no se pudo leer como un respaldo de Pivox.', previous: $e);
        }

        $backup = CompanyBackup::create([
            'uuid' => $uuid,
            'license_id' => $license->id,
            'requested_by_user_id' => $user?->id,
            'requested_by_propietario_id' => $propietarioId,
            'status' => 'inspecting',
            'file_path' => $path,
            'secret' => $password,
            'expires_at' => now()->addHours(CompanyBackup::HOURS_TO_CONFIRM),
        ]);

        InspectCompanyBackupJob::dispatch($backup->id);

        return $backup->refresh();
    }

    /** Revisado en la cola: lo que trae, para mostrar antes de confirmar. */
    public function inspected(CompanyBackup $backup, array $inspection): void
    {
        $backup->update([
            'status' => 'validated',
            'summary' => $this->importSummary($inspection),
            'total' => $inspection['total'],
            'progress' => 0,
            'error' => null,
        ]);
    }

    /**
     * Comparar con una compañía que ya existe: qué le falta, qué ya tiene y
     * qué impide completarla. En la cola: es una importación entera que se
     * deshace al terminar.
     */
    public function compare(CompanyBackup $backup, Company $company): void
    {
        $backup->update([
            'status' => 'comparing',
            'mode' => 'merge',
            'target_company_id' => $company->id,
            'comparison' => null,
            'progress' => 0,
            'error' => null,
        ]);

        CompareCompanyBackupJob::dispatch($backup->id);
    }

    /** La comparación, con lo que cuenta el resumen por sección. */
    public function compared(CompanyBackup $backup, array $report): void
    {
        $backup->update([
            'status' => 'compared',
            'comparison' => $this->mergeSummary($backup, $report),
            'progress' => $backup->total,
        ]);
    }

    /**
     * Confirmado: a la cola. Importar puede tardar, y no hace falta tener la
     * pantalla abierta.
     *
     * @param  array{legal_name: string, trade_name?: ?string, tax_id?: ?string}|null  $companyData  la compañía nueva; null, completar la comparada
     */
    public function confirm(CompanyBackup $backup, ?array $companyData, ?string $byName): void
    {
        $backup->update([
            'status' => 'queued',
            'mode' => $companyData === null ? 'merge' : 'new',
            'target_company_id' => $companyData === null ? $backup->target_company_id : null,
            'company_data' => ($companyData ?? []) + ['by' => $byName],
            'progress' => 0,
            'error' => null,
        ]);

        ImportCompanyBackupJob::dispatch($backup->id);
    }

    /**
     * Lo que se muestra de una comparación o de una importación que completó
     * una compañía: por sección, cuánto falta (o se agregó), cuánto ya estaba
     * y cuánto está distinto.
     */
    public function mergeSummary(CompanyBackup $backup, array $report): array
    {
        $sections = [];

        foreach ($backup->summary['sections'] ?? [] as $section) {
            $items = [];

            foreach (self::HIGHLIGHTS as $table => $label) {
                if (BackupCatalog::section($table) === $section['key']) {
                    $row = $report['tables'][$table] ?? [];
                    $items[] = ['label' => $label, 'added' => $row['added'] ?? 0, 'present' => $row['present'] ?? 0, 'different' => $row['different'] ?? 0];
                }
            }

            $sections[] = ['key' => $section['key'], 'label' => $section['label'], 'items' => $items];
        }

        return [
            'company' => $report['company'],
            'other_tax_id' => $report['other_tax_id'],
            'sections' => $sections,
            'totals' => $report['totals'],
            'opening' => $report['opening'],
            'conflicts' => $report['conflicts'],
            'conflicts_total' => $report['conflicts_total'],
            'people' => $report['people'],
        ];
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
     * Pivox, también se le avisa al Superusuario por correo: es su
     * información.
     */
    private function recordExport(BackupOptions $options, array $by, ?string $ip): void
    {
        $company = $options->company;
        $values = ['period' => $options->describePeriod(), 'sections' => $options->sections];

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $by['user']?->id ?? null,
            'action' => 'company.backup_exported',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'new_values' => $values + (isset($by['propietario_id']) ? ['by' => "Equipo de Pivox ({$by['name']})"] : []),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);

        if (isset($by['propietario_id'])) {
            AuditLog::create([
                'propietario_id' => $by['propietario_id'],
                'action' => 'license_company_backup_exported',
                'auditable_type' => License::class,
                'auditable_id' => $company->license_id ?? 0,
                'new_values' => $values + ['company' => $company->trade_name ?: $company->legal_name],
                'ip_address' => $ip,
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
