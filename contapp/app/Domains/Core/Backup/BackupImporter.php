<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\ActivityLogService;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Importa un respaldo (CLAUDE.md secc. 37): en una compañía nueva, o
 * completando una que ya existe con lo que le falta.
 *
 * Pasadas por el archivo:
 * 1. inspect(): descifra, verifica el SHA-256 y la firma de esta instalación,
 *    que traiga lo que esta versión exige, y cuenta. No guarda nada; es lo
 *    que se muestra antes de confirmar.
 * 2. Para completar una compañía, merge() con $dryRun: hace todo y lo
 *    deshace. Es la comparación (qué falta, qué ya está, qué lo impide).
 * 3. import() o merge(): todo o nada, en una transacción. Cada fila que
 *    entra recibe un id nuevo y sus referencias se traducen (de-para por
 *    tabla). Los códigos y los números de documento se conservan: son los
 *    consecutivos legales.
 *
 * El archivo se lee de nuevo en cada pasada, a propósito: queda cifrado en el
 * disco, y lo descifrado nunca se escribe. Descifrar es lo de menos; lo que
 * tarda es la base, y todo esto corre en la cola.
 *
 * - Lo contabilizado entra contabilizado, sin volver a contabilizarse.
 * - Lo de Pivox (monedas, módulos, impuestos, roles) no viaja: se reconoce por
 *   su código, porque cada instalación tiene sus ids. Si el respaldo usa algo
 *   que acá no existe, inspect() lo rechaza diciendo qué.
 * - Una referencia a algo que no vino (anterior al período): si la columna
 *   admite vacío, queda vacía; si no, la fila no entra, y se cuenta.
 * - Las personas, por su correo. Sus accesos (Personas y permisos), dentro
 *   del cupo de la licencia: quien no cabe queda anotado.
 */
final class BackupImporter
{
    /** Los accesos se cargan al final, cuando ya se sabe quién cabe en la licencia. */
    private const PEOPLE_TABLES = ['company_user', 'user_roles', 'screen_permissions', 'module_permissions', 'document_type_permissions'];

    /** Los que importPeople() escribe con sus propias columnas, no con las de la fila. */
    private const PEOPLE_WRITTEN = ['company_user', 'user_roles', 'screen_permissions', 'module_permissions'];

    /** @var array<string, array<int|string, int>> tabla => [id viejo => id nuevo] */
    private array $map = [];

    /** @var array<int, int> persona del respaldo => cuenta de esta instalación */
    private array $people = [];

    /** @var array<int, string> persona del respaldo => correo */
    private array $emails = [];

    /** @var array<string, true> tablas ya importadas */
    private array $done = [];

    private ?string $current = null;

    /** @var list<array{0: string, 1: int, 2: string, 3: string, 4: int}> tabla, id nuevo, columna, tabla destino, id viejo */
    private array $deferred = [];

    /** @var array<string, int> */
    private array $imported = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $peopleRows = [];

    /** @var array<string, array<int, array<string, mixed>>>|null lo de Pivox según el respaldo; null en uno anterior, que no lo trae */
    private ?array $globalKeys = null;

    /** @var array<string, array<int, ?int>> tabla => [id del respaldo => id de acá] */
    private array $globalIds = [];

    private int $companyId;

    /** Al completar una compañía que ya existe: decide qué ya está. */
    private ?BackupMerger $merger = null;

    public function __construct(
        private readonly BackupSchema $schema,
        private readonly PermissionGrantService $permissions,
    ) {}

    /**
     * Lo que trae el archivo, verificado.
     *
     * @param  callable(int, bool=): void|null  $progress
     * @return array{manifest: array<string, mixed>, rows: array<string, int>, people: int, total: int}
     */
    public function inspect(string $path, string $password, ?callable $progress = null): array
    {
        $manifest = null;
        $rows = [];
        $columns = [];
        $used = [];
        $own = [];
        $people = 0;
        $close = null;
        $hash = hash_init('sha256');
        $count = 0;

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
                // Las columnas que traen todas sus filas.
                $columns[$table] = isset($columns[$table]) ? array_intersect_key($columns[$table], $record['fila']) : array_fill_keys(array_keys($record['fila']), true);
                $this->noteGlobals($table, $record['fila'], $used, $own);

                if ($progress !== null && (++$count % 250) === 0) {
                    $progress($count);
                }
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
            throw new InvalidBackupException('Este respaldo no lo generó esta instalación de Pivox, o fue modificado: no se puede importar.');
        }

        $this->checkColumns($columns);
        $this->checkGlobals($manifest, $used, $own);
        $progress && $progress($count, true);

        return ['manifest' => $manifest, 'rows' => $rows, 'people' => $people, 'total' => array_sum($rows)];
    }

    /**
     * Importa el respaldo en una compañía nueva de esa licencia.
     *
     * @param  array{legal_name: string, trade_name?: ?string, tax_id?: ?string}  $companyData
     * @param  callable(int, bool=): void|null  $progress
     * @return array{company: Company, imported: array<string, int>, dropped: array<string, int>, people: array{added: list<string>, missing: list<string>, no_quota: list<string>}}
     */
    public function import(string $path, string $password, License $license, array $companyData, ?callable $progress = null): array
    {
        $this->reset();

        return DB::transaction(function () use ($path, $password, $license, $companyData, $progress) {
            $this->read($path, $password, $progress, fn (array $manifest) => $this->createCompany($manifest, $license, $companyData));
            $this->applyDeferred();
            $people = $this->importPeople($license);

            return [
                'company' => Company::findOrFail($this->companyId),
                'imported' => $this->imported,
                'dropped' => $this->dropped,
                'people' => $people,
            ];
        });
    }

    /**
     * Completa una compañía que ya existe con lo que le falta del respaldo
     * (BackupMerger). Con $dryRun hace todo y lo deshace: es la comparación
     * que se muestra antes de confirmar.
     *
     * @param  callable(int, bool=): void|null  $progress
     * @return array<string, mixed> lo que muestra BackupMerger::report()
     *
     * @throws InvalidBackupException si algo impide completarla (sin $dryRun)
     */
    public function merge(string $path, string $password, Company $company, bool $dryRun = false, ?callable $progress = null): array
    {
        $this->reset();
        $this->merger = new BackupMerger($this->schema, $company, $this->map);
        $license = License::findOrFail($company->license_id);

        DB::beginTransaction();

        try {
            $this->read($path, $password, $progress, fn (array $manifest) => $this->useCompany($manifest, $company));
            $this->applyDeferred();
            $people = $this->importPeople($license);
            $this->merger->finish();
            $report = $this->merger->report($this->dropped, $people);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($dryRun || $this->merger->hasConflicts()) {
            DB::rollBack();

            if (! $dryRun) {
                throw new InvalidBackupException(BackupMerger::conflictMessage($report));
            }

            return $report;
        }

        DB::commit();

        return $report;
    }

    private function reset(): void
    {
        $this->map = [];
        $this->people = [];
        $this->emails = [];
        $this->done = [];
        $this->current = null;
        $this->deferred = [];
        $this->imported = [];
        $this->dropped = [];
        $this->peopleRows = [];
        $this->globalKeys = null;
        $this->globalIds = [];
        $this->merger = null;
    }

    /** @param callable(array): void $onManifest */
    private function read(string $path, string $password, ?callable $progress, callable $onManifest): void
    {
        $done = 0;

        foreach (BackupFile::lines($path, $password) as $line) {
            $record = json_decode($line, true);

            if ($record['tipo'] === 'manifiesto') {
                $this->globalKeys = $record['globales'] ?? null;
            }

            match ($record['tipo']) {
                'manifiesto' => $onManifest($record),
                'persona' => $this->person($record),
                'fila' => $this->row($record['tabla'], $record['fila']),
                default => null,
            };

            if ($record['tipo'] === 'fila' && $progress !== null && (++$done % 250) === 0) {
                $progress($done);
            }
        }

        $progress && $progress($done, true);
    }

    // ── El encabezado ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function checkManifest(array $manifest): array
    {
        if (($manifest['formato'] ?? null) !== BackupExporter::FORMAT || (int) ($manifest['version'] ?? 0) !== BackupExporter::VERSION) {
            throw new InvalidBackupException('Este archivo no es un respaldo de Pivox que esta versión entienda.');
        }

        // Un respaldo de una versión más nueva de Pivox (con tablas o
        // columnas que esta todavía no tiene) no se importa a medias.
        if (! DB::table('migrations')->where('migration', (string) ($manifest['esquema'] ?? ''))->exists()) {
            throw new InvalidBackupException('Este respaldo es de una versión más nueva de Pivox. Actualizá esta instalación antes de importarlo.');
        }

        return $manifest;
    }

    private function checkTable(array $record): string
    {
        $table = (string) ($record['tabla'] ?? '');

        if (! BackupCatalog::includes($table) || ! is_array($record['fila'] ?? null)) {
            throw new InvalidBackupException('El respaldo trae datos que esta versión de Pivox no reconoce.');
        }

        return $table;
    }

    /**
     * Un respaldo de una versión anterior: lo que esta versión exige y el
     * respaldo no trae tiene que tener con qué completarse (BackupCatalog::FILL).
     * Si no, se dice ahora, antes de confirmar, y no a mitad de la importación.
     *
     * @param  array<string, array<string, true>>  $columns  tabla => columnas que traen todas sus filas
     */
    private function checkColumns(array $columns): void
    {
        $gaps = [];

        foreach ($columns as $table => $present) {
            if (in_array($table, self::PEOPLE_WRITTEN, true)) {
                continue;
            }

            foreach ($this->schema->requiredColumns($table) as $column) {
                if (! isset($present[$column]) && ! array_key_exists($column, BackupCatalog::FILL[$table] ?? [])) {
                    $gaps[] = "{$table}.{$column}";
                }
            }
        }

        if ($gaps !== []) {
            throw new InvalidBackupException('Este respaldo es de una versión anterior de Pivox y le faltan datos que esta versión exige ('
                .implode(', ', array_slice($gaps, 0, 5)).(count($gaps) > 5 ? '…' : '').'). Avisale al equipo de Pivox: hay que indicar con qué completarlos antes de importarlo.');
        }
    }

    /**
     * Lo de Pivox que usa una fila. Las filas propias de una tabla mixta
     * vienen en el respaldo: esas no hay que buscarlas.
     *
     * @param  array<string, array<int, true>>  $used
     * @param  array<string, array<int, true>>  $own
     */
    private function noteGlobals(string $table, array $row, array &$used, array &$own): void
    {
        foreach ($this->schema->references($table) as $column => $target) {
            if (isset(BackupCatalog::GLOBAL_KEYS[$target], $row[$column])) {
                $used[$target][(int) $row[$column]] = true;
            }
        }

        if (isset(BackupCatalog::GLOBAL_KEYS[$table], $row['id'])) {
            $own[$table][(int) $row['id']] = true;
        }
    }

    /**
     * Lo de Pivox que el respaldo usa tiene que existir acá. Sin eso, sus filas
     * no entrarían (una línea de asiento sin moneda) o entrarían sin el dato
     * (una cuenta sin su tarifa), y solo se notaría después: se dice ahora,
     * antes de confirmar.
     *
     * @param  array<string, array<int, true>>  $used
     * @param  array<string, array<int, true>>  $own
     */
    private function checkGlobals(array $manifest, array $used, array $own): void
    {
        $this->globalKeys = $manifest['globales'] ?? null;
        $this->globalIds = [];

        foreach (['local_currency_id', 'foreign_currency_id', 'system_currency_id'] as $column) {
            if (isset($manifest['compania'][$column])) {
                $used['currencies'][(int) $manifest['compania'][$column]] = true;
            }
        }

        $gaps = [];

        foreach (BackupCatalog::GLOBAL_LABELS as $table => $label) {
            foreach (array_keys(array_diff_key($used[$table] ?? [], $own[$table] ?? [])) as $id) {
                if ($this->global($table, $id) === null) {
                    $gaps[] = $label.' '.(array_values($this->globalKeys[$table][$id] ?? [])[0] ?? "#{$id}");
                }
            }
        }

        if ($gaps === []) {
            return;
        }

        $list = implode(', ', array_slice($gaps, 0, 8)).(count($gaps) > 8 ? '…' : '');

        throw new InvalidBackupException($this->globalKeys === null
            ? "Este respaldo es de una versión anterior de Pivox y usa lo que esta instalación no tiene ({$list}). Exportalo de nuevo desde donde salió."
            : "Este respaldo usa lo que esta instalación de Pivox no tiene: {$list}. Hay que cargarlo antes de importar.");
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

    /** Completar: la compañía es la que ya existe; de la del respaldo no se toca nada. */
    private function useCompany(array $manifest, Company $company): void
    {
        $this->companyId = $company->id;
        $this->map['companies'][(int) $manifest['compania']['id']] = $company->id;

        // La moneda del respaldo, en los ids de esta instalación.
        $source = $manifest['compania'];
        $source['local_currency_id'] = $this->global('currencies', $source['local_currency_id'] ?? null);
        $this->merger->start(['compania' => $source] + $manifest);
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
        $later = $missing = $unresolved = [];

        if ($this->merger?->skipsOpening($table, $row)) {
            $this->merger->observe($table, $this->translate($table, $row, $later, $missing, $unresolved));

            return;
        }

        $values = $this->special($table, $this->translate($table, $row, $later, $missing, $unresolved));

        if ($this->merger !== null && $values !== null) {
            $found = $this->merger->find($table, $row, $values, $unresolved);

            // Ya está: lo que cuelgue de ella apunta a la de acá.
            if (is_int($found)) {
                $this->map[$table][$oldId] = $found;
            }

            if ($found !== null) {
                return;
            }
        }

        if ($values === null || $missing !== []) {
            $this->dropped[$table] = ($this->dropped[$table] ?? 0) + 1;

            return;
        }

        // Lo que esta versión exige y un respaldo anterior no trae.
        $values += array_diff_key(BackupCatalog::FILL[$table] ?? [], $values);

        $newId = DB::table($table)->insertGetId($values);
        $this->map[$table][$oldId] = $newId;
        $this->imported[$table] = ($this->imported[$table] ?? 0) + 1;
        $this->merger?->inserted($table, $newId, (int) $oldId < 0);

        foreach ($later as [$column, $target, $old]) {
            $this->deferred[] = [$table, $newId, $column, $target, $old];
        }
    }

    /**
     * La fila con sus referencias traducidas.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $later  referencias que se completan al final
     * @param  list<string>  $missing  columnas obligatorias sin a qué apuntar: la fila no puede entrar
     * @param  list<string>  $unresolved  todas las referencias que no se pudieron traducir
     * @return array<string, mixed>
     */
    private function translate(string $table, array $row, ?array &$later, ?array &$missing, ?array &$unresolved): array
    {
        $later = [];
        $missing = [];
        $unresolved = [];
        $columns = array_flip($this->schema->columns($table));
        $values = array_intersect_key($row, $columns);
        unset($values['id']);

        foreach ($this->schema->references($table) as $column => $target) {
            if (! array_key_exists($column, $values) || $values[$column] === null) {
                continue;
            }

            $old = $values[$column];
            $new = $this->reference($target, $old);

            if ($new === null) {
                $unresolved[] = $column;

                // Una obligatoria no espera al final: lo que exige viene
                // antes (BackupSchema::order). Si no está, no hay a qué
                // apuntar, aunque su tabla no haya aparecido en el respaldo.
                if (! $this->schema->isNullable($table, $column)) {
                    $missing[] = $column;
                } elseif ($this->comesLater($table, $target)) {
                    $later[] = [$column, $target, (int) $old];
                }
            }

            $values[$column] = $new;
        }

        return $values;
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

    /** Un registro de Pivox (moneda, módulo, tarifa nacional): el de esta instalación. */
    private function global(string $table, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;

        if (! array_key_exists($id, $this->globalIds[$table] ?? [])) {
            $this->globalIds[$table][$id] = $this->findGlobal($table, $id);
        }

        return $this->globalIds[$table][$id];
    }

    /**
     * Por lo que lo reconoce en cualquier instalación, no por su id. Un
     * respaldo anterior no lo trae: va por su id, que vale si se exportó acá.
     * Un rol de Pivox se crea la primera vez que hace falta, como al invitar.
     */
    private function findGlobal(string $table, int $id): ?int
    {
        $query = DB::table($table)->when(in_array($table, BackupCatalog::MIXED_TABLES, true), fn ($q) => $q->whereNull('company_id'));

        if ($this->globalKeys === null) {
            $found = $query->where('id', $id)->value('id');

            return $found === null ? null : (int) $found;
        }

        $wanted = $this->globalKeys[$table][$id] ?? null;

        if ($wanted === null) {
            return null;
        }

        $key = array_key_first($wanted);
        $rows = $query->where($key, $wanted[$key])->orderByDesc('id')->get(['id', ...array_keys($wanted)]);
        $found = $rows->first(fn ($row) => array_diff_assoc($wanted, (array) $row) === []) ?? $rows->first();

        if ($found !== null) {
            return (int) $found->id;
        }

        return $table === 'roles' ? $this->permissions->globalRole($wanted['type'])->id : null;
    }

    /** Una tabla que todavía no se importó (un ciclo, o la misma tabla): se completa al final. */
    private function comesLater(string $table, string $target): bool
    {
        return BackupCatalog::includes($target) && ! isset($this->done[$target]);
    }

    private function markDoneBefore(string $table): void
    {
        if ($this->current !== $table) {
            if ($this->current !== null) {
                $this->done[$this->current] = true;
            }

            $this->current = $table;
        }
    }

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
     * Al completar una compañía, quien ya tiene acceso queda como está: no se
     * le suman ni se le cambian permisos.
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
            // rol —el Superusuario de la licencia de origen— no se suma; y
            // quien ya tiene acceso a la compañía, tampoco.
            if ($userId === (int) $license->superuser_id || $type === null
                || DB::table('company_user')->where('company_id', $this->companyId)->where('user_id', $userId)->exists()) {
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
                // Los permisos de un rol, en un tipo de documento que ya
                // estaba, son de esta compañía: no se tocan.
                'role' => $documentType && $this->merger?->isPresent('document_types', $documentType) ? null : $this->reference('roles', $row['subject_id']),
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

    /**
     * Para la Bitácora de la compañía: creada desde un respaldo, o completada
     * con uno (con cuánto se le agregó).
     */
    public static function recordImport(Company $company, ?User $user, ?string $byName, array $manifest, ?int $added = null): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'action' => $added === null ? 'company.backup_imported' : 'company.backup_merged',
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'new_values' => array_filter([
                'from' => $manifest['compania']['trade_name'] ?? $manifest['compania']['legal_name'] ?? null,
                'period' => $manifest['periodo']['texto'] ?? null,
                'added' => $added,
                'by' => $byName,
            ], fn ($value) => $value !== null),
            'created_at' => now(),
        ]);
    }
}
