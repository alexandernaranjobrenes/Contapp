<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\ActivityLogService;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Importa un respaldo en una compañía nueva (CLAUDE.md secc. 37). Nunca
 * encima de una que ya existe: mezclar daría dos FVE-123, códigos repetidos
 * y asientos duplicados.
 *
 * Dos pasadas por el archivo:
 * 1. inspect(): descifra, verifica el SHA-256 y la firma de esta instalación,
 *    y cuenta. No guarda nada; es lo que se muestra antes de confirmar.
 * 2. import(): todo o nada, en una transacción. Cada fila recibe un id nuevo
 *    y sus referencias se traducen (de-para por tabla). Los códigos y los
 *    números de documento se conservan: son los consecutivos legales.
 *
 * - Lo contabilizado entra contabilizado, sin volver a contabilizarse.
 * - Una referencia a algo que no vino (anterior al período): si la columna
 *   admite vacío, queda vacía; si no, la fila no entra, y se cuenta.
 * - Las personas, por su correo. Sus accesos (Personas y permisos), dentro
 *   del cupo de la licencia: quien no cabe queda anotado.
 */
final class BackupImporter
{
    /** Los accesos se cargan al final, cuando ya se sabe quién cabe en la licencia. */
    private const PEOPLE_TABLES = ['company_user', 'user_roles', 'screen_permissions', 'module_permissions', 'document_type_permissions'];

    /** @var array<string, array<int|string, int>> tabla => [id viejo => id nuevo] */
    private array $map = [];

    /** @var array<int, int> persona del respaldo => cuenta de esta instalación */
    private array $people = [];

    /** @var array<int, string> persona del respaldo => correo */
    private array $emails = [];

    /** @var array<string, true> tablas ya importadas */
    private array $done = [];

    /** @var list<array{0: string, 1: int, 2: string, 3: string, 4: int}> tabla, id nuevo, columna, tabla destino, id viejo */
    private array $deferred = [];

    /** @var array<string, int> */
    private array $imported = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $peopleRows = [];

    /** @var array<string, array<int, bool>> */
    private array $globalExists = [];

    private int $companyId;

    public function __construct(private readonly BackupSchema $schema) {}

    /**
     * Lo que trae el archivo, verificado.
     *
     * @return array{manifest: array<string, mixed>, rows: array<string, int>, people: int, total: int}
     */
    public function inspect(string $path, string $password): array
    {
        $manifest = null;
        $rows = [];
        $people = 0;
        $close = null;
        $hash = hash_init('sha256');

        foreach (BackupFile::lines($path, $password) as $line) {
            if ($close !== null) {
                throw new InvalidBackupException('El respaldo tiene contenido después del cierre.');
            }

            $record = json_decode($line, true);

            if (! is_array($record) || ! isset($record['tipo'])) {
                throw new InvalidBackupException('El contenido del respaldo no se entiende.');
            }

            if ($record['tipo'] === 'cierre') {
                $close = $record;

                continue;
            }

            hash_update($hash, $line."\n");

            if ($record['tipo'] === 'manifiesto') {
                if ($manifest !== null) {
                    throw new InvalidBackupException('El respaldo tiene dos encabezados.');
                }

                $manifest = $this->checkManifest($record);
            } elseif ($record['tipo'] === 'persona') {
                $people++;
            } elseif ($record['tipo'] === 'fila') {
                $table = $this->checkTable($record);
                $rows[$table] = ($rows[$table] ?? 0) + 1;
            } else {
                throw new InvalidBackupException('El contenido del respaldo no se entiende.');
            }
        }

        if ($manifest === null) {
            throw new InvalidBackupException('El respaldo no tiene encabezado.');
        }

        if ($close === null) {
            throw new InvalidBackupException('El respaldo está incompleto: falta el cierre.');
        }

        $digest = hash_final($hash);

        if (! hash_equals($digest, (string) ($close['sha256'] ?? '')) || $rows !== array_map('intval', $close['filas'] ?? [])) {
            throw new InvalidBackupException('El respaldo está dañado: lo que trae no coincide con su cierre.');
        }

        if (! BackupSignature::verify($digest, (string) ($close['firma'] ?? ''))) {
            throw new InvalidBackupException('Este respaldo no lo generó esta instalación de CONTAPP, o fue modificado: no se puede importar.');
        }

        return ['manifest' => $manifest, 'rows' => $rows, 'people' => $people, 'total' => array_sum($rows)];
    }

    /**
     * Importa el respaldo en una compañía nueva de esa licencia.
     *
     * @param  array{legal_name: string, trade_name?: ?string, tax_id?: ?string}  $companyData
     * @param  callable(int): void|null  $progress
     * @return array{company: Company, imported: array<string, int>, dropped: array<string, int>, people: array{added: list<string>, missing: list<string>, no_quota: list<string>}}
     */
    public function import(string $path, string $password, License $license, array $companyData, ?callable $progress = null): array
    {
        return DB::transaction(function () use ($path, $password, $license, $companyData, $progress) {
            $done = 0;

            foreach (BackupFile::lines($path, $password) as $line) {
                $record = json_decode($line, true);

                match ($record['tipo']) {
                    'manifiesto' => $this->createCompany($record, $license, $companyData),
                    'persona' => $this->person($record),
                    'fila' => $this->row($record['tabla'], $record['fila']),
                    default => null,
                };

                if ($record['tipo'] === 'fila' && $progress !== null && (++$done % 250) === 0) {
                    $progress($done);
                }
            }

            $this->applyDeferred();
            $people = $this->importPeople($license);
            $company = Company::findOrFail($this->companyId);

            $progress && $progress($done);

            return [
                'company' => $company,
                'imported' => $this->imported,
                'dropped' => $this->dropped,
                'people' => $people,
            ];
        });
    }

    // ── El encabezado ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function checkManifest(array $manifest): array
    {
        if (($manifest['formato'] ?? null) !== BackupExporter::FORMAT || (int) ($manifest['version'] ?? 0) !== BackupExporter::VERSION) {
            throw new InvalidBackupException('Este archivo no es un respaldo de CONTAPP que esta versión entienda.');
        }

        // Un respaldo de una versión más nueva de CONTAPP (con tablas o
        // columnas que esta todavía no tiene) no se importa a medias.
        if (! DB::table('migrations')->where('migration', (string) ($manifest['esquema'] ?? ''))->exists()) {
            throw new InvalidBackupException('Este respaldo es de una versión más nueva de CONTAPP. Actualizá esta instalación antes de importarlo.');
        }

        return $manifest;
    }

    private function checkTable(array $record): string
    {
        $table = (string) ($record['tabla'] ?? '');

        if (! BackupCatalog::includes($table) || ! is_array($record['fila'] ?? null)) {
            throw new InvalidBackupException('El respaldo trae datos que esta versión de CONTAPP no reconoce.');
        }

        return $table;
    }

    private function createCompany(array $manifest, License $license, array $data): void
    {
        $source = $manifest['compania'];
        $now = now();

        $this->companyId = DB::table('companies')->insertGetId([
            'license_id' => $license->id,
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'] ?? $data['legal_name'],
            'tax_id' => $data['tax_id'] ?? null,
            'address' => $source['address'] ?? null,
            'country_code' => $source['country_code'] ?? 'CR',
            'local_currency_id' => $this->global('currencies', $source['local_currency_id'] ?? null),
            'foreign_currency_id' => $this->global('currencies', $source['foreign_currency_id'] ?? null),
            'system_currency_id' => $this->global('currencies', $source['system_currency_id'] ?? null),
            'timezone' => $source['timezone'] ?? 'America/Costa_Rica',
            'theme' => $source['theme'] ?? null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->map['companies'][(int) $source['id']] = $this->companyId;
    }

    private function person(array $record): void
    {
        $this->emails[(int) $record['id']] = (string) $record['correo'];
        $userId = User::where('email', $record['correo'])->value('id');

        if ($userId !== null) {
            $this->people[(int) $record['id']] = (int) $userId;
        }
    }

    // ── Las filas ───────────────────────────────────────────────────────

    private function row(string $table, array $row): void
    {
        $this->markDoneBefore($table);

        if (in_array($table, self::PEOPLE_TABLES, true)) {
            $this->peopleRows[$table][] = $row;

            return;
        }

        $oldId = $row['id'];
        $values = $this->translate($table, $row, $later);

        if ($values === null) {
            $this->dropped[$table] = ($this->dropped[$table] ?? 0) + 1;

            return;
        }

        $newId = DB::table($table)->insertGetId($values);
        $this->map[$table][$oldId] = $newId;
        $this->imported[$table] = ($this->imported[$table] ?? 0) + 1;

        foreach ($later as [$column, $target, $old]) {
            $this->deferred[] = [$table, $newId, $column, $target, $old];
        }
    }

    /**
     * La fila con sus referencias traducidas, o null si no puede entrar.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $later  referencias que se completan al final
     * @return array<string, mixed>|null
     */
    private function translate(string $table, array $row, ?array &$later): ?array
    {
        $later = [];
        $columns = array_flip($this->schema->columns($table));
        $values = array_intersect_key($row, $columns);
        unset($values['id']);

        foreach ($this->schema->references($table) as $column => $target) {
            if (! array_key_exists($column, $values) || $values[$column] === null) {
                continue;
            }

            $old = $values[$column];
            $new = $this->reference($target, $old);

            if ($new === null && $this->comesLater($table, $target)) {
                $later[] = [$column, $target, (int) $old];
            }

            if ($new === null && ! $this->schema->isNullable($table, $column) && ! $this->comesLater($table, $target)) {
                return null;
            }

            $values[$column] = $new;
        }

        return $this->special($table, $values);
    }

    /** La referencia traducida, o null si no hay a qué apuntar. */
    private function reference(string $target, mixed $old): ?int
    {
        $old = (int) $old;

        return match (true) {
            $target === 'companies' => $this->companyId,
            $target === 'users' => $this->people[$old] ?? null,
            in_array($target, BackupCatalog::GLOBAL_TABLES, true) => $this->global($target, $old),
            in_array($target, BackupCatalog::MIXED_TABLES, true) => $this->map[$target][$old] ?? $this->global($target, $old),
            default => $this->map[$target][$old] ?? null,
        };
    }

    /** Un registro de CONTAPP (moneda, módulo, tarifa nacional): el mismo id, si existe. */
    private function global(string $table, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;

        if (! isset($this->globalExists[$table][$id])) {
            $query = DB::table($table)->where('id', $id);

            if (in_array($table, BackupCatalog::MIXED_TABLES, true)) {
                $query->whereNull('company_id');
            }

            $this->globalExists[$table][$id] = $query->exists();
        }

        return $this->globalExists[$table][$id] ? $id : null;
    }

    /** Una tabla que todavía no se importó (un ciclo, o la misma tabla): se completa al final. */
    private function comesLater(string $table, string $target): bool
    {
        return BackupCatalog::includes($target) && ! isset($this->done[$target]);
    }

    private function markDoneBefore(string $table): void
    {
        if (! isset($this->current) || $this->current !== $table) {
            if (isset($this->current)) {
                $this->done[$this->current] = true;
            }

            $this->current = $table;
        }
    }

    private ?string $current = null;

    /**
     * Lo que no es una llave foránea: referencias según su tipo, ids
     * adentro de un JSON.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function special(string $table, array $values): ?array
    {
        switch ($table) {
            case 'gl_determinations':
                $targets = ['item' => 'items', 'item_group' => 'item_groups', 'warehouse' => 'warehouses'];

                if ($values['scope_id'] !== null) {
                    $values['scope_id'] = $values['scope_level'] === 'company'
                        ? $this->companyId
                        : ($this->map[$targets[$values['scope_level']] ?? ''][(int) $values['scope_id']] ?? null);

                    if ($values['scope_id'] === null) {
                        return null;
                    }
                }

                break;

            case 'journal_entry_schedules':
                $lines = is_string($values['lines']) ? json_decode($values['lines'], true) : $values['lines'];
                $references = ['account_id' => 'chart_of_accounts', 'business_partner_id' => 'business_partners', 'cost_allocation_rule_id' => 'cost_allocation_rules'];

                foreach ($lines as $i => $line) {
                    foreach ($references as $key => $target) {
                        if (isset($line[$key])) {
                            $lines[$i][$key] = $this->map[$target][(int) $line[$key]] ?? null;
                        }
                    }
                }

                $values['lines'] = json_encode($lines, JSON_UNESCAPED_UNICODE);

                break;

            case 'personnel_actions':
                if (($values['field'] ?? null) === 'cost_center_id') {
                    foreach (['previous_value', 'new_value'] as $key) {
                        if (is_numeric($values[$key] ?? null)) {
                            $values[$key] = (string) ($this->map['cost_centers'][(int) $values[$key]] ?? $values[$key]);
                        }
                    }
                }

                break;

            case 'audit_logs':
                return $this->auditRow($values);
        }

        // Una columna JSON llega como texto; si en el camino se armó como
        // arreglo, vuelve a texto.
        foreach ($values as $column => $value) {
            if (is_array($value)) {
                $values[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $values;
    }

    /**
     * Una fila de la Bitácora: a quién se refiere y las referencias que lleva
     * adentro (ActivityLogService las muestra por su nombre).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditRow(array $values): array
    {
        $values['auditable_id'] = $this->modelId((string) $values['auditable_type'], $values['auditable_id']) ?? 0;

        $changes = is_string($values['changes'] ?? null) ? json_decode($values['changes'], true) : ($values['changes'] ?? null);

        if (is_array($changes)) {
            $references = ActivityLogService::referenceColumns();

            foreach ($changes['records'] ?? [] as $i => $record) {
                $changes['records'][$i]['id'] = $this->modelId((string) ($record['t'] ?? ''), $record['id'] ?? null);

                foreach ($record['f'] ?? [] as $field => $pair) {
                    if (isset($references[$field]) && is_array($pair)) {
                        $changes['records'][$i]['f'][$field] = array_map(
                            fn ($value) => is_numeric($value) ? $this->modelId($references[$field], $value) : $value,
                            $pair,
                        );
                    }
                }
            }
        }

        foreach (['changes' => $changes, 'old_values' => $values['old_values'] ?? null, 'new_values' => $values['new_values'] ?? null] as $key => $value) {
            $values[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }

        return $values;
    }

    /** El id nuevo de un registro, por su clase. */
    private function modelId(string $class, mixed $id): ?int
    {
        if ($id === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $table = (new $class)->getTable();

        return $this->reference($table, $id);
    }

    private function applyDeferred(): void
    {
        foreach ($this->deferred as [$table, $id, $column, $target, $old]) {
            $new = $this->map[$target][$old] ?? null;

            if ($new !== null) {
                DB::table($table)->where('id', $id)->update([$column => $new]);
            }
        }
    }

    // ── Las personas y sus accesos ──────────────────────────────────────

    /**
     * Los accesos de cada persona a la compañía, si su cuenta existe en esta
     * instalación y cabe en la licencia. El Superusuario de la licencia
     * siempre entra.
     *
     * @return array{added: list<string>, missing: list<string>, no_quota: list<string>}
     */
    private function importPeople(License $license): array
    {
        $added = [];
        $missing = [];
        $noQuota = [];
        $now = now();

        // Quién tiene qué tipo de rol (admin o user) en el respaldo.
        $roleTypes = [];

        foreach ($this->peopleRows['user_roles'] ?? [] as $row) {
            $roleId = $this->reference('roles', $row['role_id']);
            $type = $roleId ? DB::table('roles')->where('id', $roleId)->value('type') : null;

            if ($type !== null) {
                $roleTypes[(int) $row['user_id']] = $type;
            }
        }

        $accepted = [];

        foreach ($this->peopleRows['company_user'] ?? [] as $row) {
            $old = (int) $row['user_id'];
            $userId = $this->people[$old] ?? null;
            $email = $this->emails[$old] ?? '';

            if ($userId === null) {
                $missing[] = $email;

                continue;
            }

            $type = $roleTypes[$old] ?? null;

            // El Superusuario de la licencia entra solo (abajo); alguien sin
            // rol —el Superusuario de la licencia de origen— no se suma.
            if ($userId === (int) $license->superuser_id || $type === null) {
                continue;
            }

            if (! $this->fitsInLicense($license, $userId, $type)) {
                $noQuota[] = $email;

                continue;
            }

            DB::table('company_user')->insert(['company_id' => $this->companyId, 'user_id' => $userId, 'is_default' => false, 'status' => $row['status'] ?? 'active', 'created_at' => $now, 'updated_at' => $now]);
            $accepted[$old] = $userId;
            $added[] = $email;

            // El rol, enseguida: el siguiente ya se cuenta con este.
            foreach ($this->peopleRows['user_roles'] ?? [] as $roleRow) {
                if ((int) $roleRow['user_id'] === $old && ($roleId = $this->reference('roles', $roleRow['role_id']))) {
                    DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId, 'company_id' => $this->companyId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        // El Superusuario de la licencia, siempre.
        if ($license->superuser_id && ! DB::table('company_user')->where('company_id', $this->companyId)->where('user_id', $license->superuser_id)->exists()) {
            DB::table('company_user')->insert(['company_id' => $this->companyId, 'user_id' => $license->superuser_id, 'is_default' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ($this->peopleRows['screen_permissions'] ?? [] as $row) {
            if (isset($accepted[(int) $row['user_id']])) {
                DB::table('screen_permissions')->insert(['company_id' => $this->companyId, 'user_id' => $accepted[(int) $row['user_id']], 'screen' => $row['screen'], 'access_level' => $row['access_level'], 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach ($this->peopleRows['module_permissions'] ?? [] as $row) {
            $moduleId = $this->global('modules', $row['module_id']);

            if ($row['subject_type'] === 'user' && isset($accepted[(int) $row['subject_id']]) && $moduleId) {
                DB::table('module_permissions')->insert(['company_id' => $this->companyId, 'module_id' => $moduleId, 'subject_type' => 'user', 'subject_id' => $accepted[(int) $row['subject_id']], 'access_level' => $row['access_level'], 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach ($this->peopleRows['document_type_permissions'] ?? [] as $row) {
            $documentType = $this->map['document_types'][(int) $row['document_type_id']] ?? null;
            $subject = match ($row['subject_type']) {
                'user' => $accepted[(int) $row['subject_id']] ?? null,
                'role' => $this->reference('roles', $row['subject_id']),
                default => null,
            };

            if ($documentType && $subject) {
                $values = array_intersect_key($row, array_flip($this->schema->columns('document_type_permissions')));
                unset($values['id']);
                DB::table('document_type_permissions')->insert(['document_type_id' => $documentType, 'subject_id' => $subject] + $values);
            }
        }

        return ['added' => $added, 'missing' => array_values(array_filter($missing)), 'no_quota' => $noQuota];
    }

    /** ¿La persona entra en la licencia con ese tipo de rol? Si ya lo tiene en otra compañía de la licencia, no suma. */
    private function fitsInLicense(License $license, int $userId, string $type): bool
    {
        $companyIds = DB::table('companies')->where('license_id', $license->id)->pluck('id');
        $already = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.company_id', $companyIds)
            ->where('user_roles.user_id', $userId)
            ->where('roles.type', $type)
            ->exists();

        if ($already) {
            return true;
        }

        $license->refresh();

        return $type === 'admin' ? $license->canAddAnotherAdmin() : $license->canAddAnotherUser();
    }

    /** Para la Bitácora de la compañía nueva. */
    public static function recordImport(Company $company, ?User $user, ?string $byName, array $manifest): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'action' => 'company.backup_imported',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'new_values' => array_filter([
                'from' => $manifest['compania']['trade_name'] ?? $manifest['compania']['legal_name'] ?? null,
                'period' => $manifest['periodo']['texto'] ?? null,
                'by' => $byName,
            ]),
            'created_at' => now(),
        ]);
    }
}
