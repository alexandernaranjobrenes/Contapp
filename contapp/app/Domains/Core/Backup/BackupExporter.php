<?php

namespace App\Domains\Core\Backup;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Arma el respaldo de una compañía: una línea JSON por registro (JSON Lines),
 * en el orden en que se importa (cada fila después de las que necesita).
 *
 * 1. El encabezado: la compañía, el período, las secciones, quién y cuándo.
 * 2. Las personas que aparecen (quién creó, aprobó, contabilizó…), con su
 *    correo: al importar se reconocen por él.
 * 3. Las filas de cada tabla, con los saldos iniciales (BackupOpening) y el
 *    estado al cierre (BackupState).
 * 4. El cierre: cuántas filas de cada tabla, el SHA-256 de todo lo anterior y
 *    la firma de esta instalación (BackupSignature).
 */
final class BackupExporter
{
    public const FORMAT = 'contapp-respaldo';

    public const VERSION = 1;

    public function __construct(private readonly BackupSchema $schema) {}

    /**
     * @param  array{name: string, email: string, role: string}  $exportedBy
     * @return \Generator<string>
     */
    public function lines(BackupOptions $options, array $exportedBy): \Generator
    {
        $opening = BackupOpening::build($options);
        $state = new BackupState($options, $opening);
        $hash = hash_init('sha256');
        $counts = [];

        $emit = function (array $record) use ($hash): string {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            hash_update($hash, $line."\n");

            return $line;
        };

        yield $emit($this->manifest($options, $exportedBy, $opening));

        foreach ($this->people($options) as $person) {
            yield $emit(['tipo' => 'persona'] + $person);
        }

        foreach ($this->schema->order($options->tables()) as $table) {
            $counts[$table] = 0;

            foreach ($this->rows($table, $options, $opening, $state) as $row) {
                $counts[$table]++;

                yield $emit(['tipo' => 'fila', 'tabla' => $table, 'fila' => $row]);
            }
        }

        $digest = hash_final($hash);

        yield json_encode([
            'tipo' => 'cierre',
            'filas' => array_filter($counts),
            'sha256' => $digest,
            'firma' => BackupSignature::sign($digest),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Cuántas filas de cada tabla lleva el respaldo, para el resumen antes
     * de exportar. Los saldos iniciales, aparte.
     *
     * @return array{tables: array<string, int>, opening: array<string, int>}
     */
    public function counts(BackupOptions $options): array
    {
        $opening = BackupOpening::build($options);
        $tables = [];

        foreach ($options->tables() as $table) {
            $tables[$table] = match (BackupCatalog::form($table)) {
                'state' => $this->stateCount($table, $options, $opening),
                default => $this->query($table, $options)->count() + count($opening->rowsFor($table)),
            };
        }

        return ['tables' => $tables, 'opening' => $opening->counts()];
    }

    /** @return \Generator<array<string, mixed>> */
    private function rows(string $table, BackupOptions $options, BackupOpening $opening, BackupState $state): \Generator
    {
        if ($table === 'bp_open_items') {
            yield from $state->openItems();

            return;
        }

        if (in_array($table, ['item_warehouses', 'item_bins', 'item_lot_stock', 'item_serials'], true)) {
            yield from $state->stock($table);

            return;
        }

        yield from $opening->rowsFor($table);

        $blank = BackupCatalog::BLANK_COLUMNS[$table] ?? [];

        foreach ($this->query($table, $options)->lazyById(1000, $table.'.id', 'id') as $row) {
            $row = (array) $row;

            foreach ($blank as $column) {
                $row[$column] = null;
            }

            if ($table === 'document_types') {
                $row = $opening->documentTypeChanges((int) $row['id']) + $row;
            }

            yield $state->adjust($table, $row);
        }
    }

    /**
     * Las filas de la tabla que van en el respaldo: las de la compañía, las
     * del período y las que cuelgan de una fila que va.
     */
    public function query(string $table, BackupOptions $options): Builder
    {
        $query = DB::table($table)->select("{$table}.*");
        $parent = BackupCatalog::option($table, 'parent');
        $date = BackupCatalog::option($table, 'date');

        if ($this->schema->hasColumn($table, 'company_id')) {
            $query->where("{$table}.company_id", $options->company->id);
        }

        if ($parent !== null) {
            $parentTable = $this->schema->references($table)[$parent];
            $query->whereIn("{$table}.{$parent}", $this->query($parentTable, $options)->select("{$parentTable}.id"));
        }

        if ($date !== null) {
            $from = $options->fromDate();

            // Las planillas traen además los doce meses anteriores: el
            // aguinaldo y las liquidaciones se calculan con ellos.
            if ($table === 'payroll_periods' && $from !== null) {
                $from = $options->from->copy()->subMonthsNoOverflow(12)->format('Y-m-d');
            }

            $query->when($from, fn ($q) => $q->where("{$table}.{$date}", '>=', $from))
                ->when($options->toDate(), fn ($q) => $q->where("{$table}.{$date}", '<', BackupState::nextDay($options->toDate())));
        }

        return match ($table) {
            // La Bitácora de la compañía, no la del backoffice.
            'audit_logs' => $query->whereNull('audit_logs.propietario_id'),
            // Una conciliación entra entera o no entra: si una de sus
            // partidas es de antes del período, no cuadraría.
            'account_reconciliations', 'bank_reconciliations' => $this->wholeReconciliations($query, $table, $options),
            default => $query,
        };
    }

    private function wholeReconciliations(Builder $query, string $table, BackupOptions $options): Builder
    {
        if ($options->from === null && $options->to === null) {
            return $query;
        }

        $lines = $table === 'account_reconciliations' ? 'account_reconciliation_lines' : 'bank_reconciliation_lines';
        $key = $table === 'account_reconciliations' ? 'account_reconciliation_id' : 'bank_reconciliation_id';

        return $query->whereNotExists(function ($q) use ($table, $lines, $key, $options) {
            $q->selectRaw('1')
                ->from($lines)
                ->join('journal_details', 'journal_details.id', '=', "{$lines}.journal_detail_id")
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
                ->whereColumn("{$lines}.{$key}", "{$table}.id")
                ->where(function ($q) use ($options) {
                    $q->when($options->fromDate(), fn ($q, $from) => $q->orWhere('journal_entries.posting_date', '<', $from))
                        ->when($options->toDate(), fn ($q, $to) => $q->orWhere('journal_entries.posting_date', '>', $to));
                });
        });
    }

    private function stateCount(string $table, BackupOptions $options, BackupOpening $opening): int
    {
        $state = new BackupState($options, $opening);
        $count = 0;

        foreach ($table === 'bp_open_items' ? $state->openItems() : $state->stock($table) as $row) {
            $count++;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private function manifest(BackupOptions $options, array $exportedBy, BackupOpening $opening): array
    {
        $company = (array) DB::table('companies')->where('id', $options->company->id)->first();
        unset($company['logo_path'], $company['logo_updated_at'], $company['license_id']);

        return [
            'tipo' => 'manifiesto',
            'formato' => self::FORMAT,
            'version' => self::VERSION,
            'esquema' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'compania' => $company,
            'periodo' => [
                'desde' => $options->fromDate(),
                'hasta' => $options->toDate(),
                'texto' => $options->describePeriod(),
            ],
            'secciones' => $options->sections,
            'saldos_iniciales' => $opening->counts(),
            'exportado' => [
                'por' => $exportedBy['name'],
                'correo' => $exportedBy['email'],
                'como' => $exportedBy['role'],
                'el' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Las personas que aparecen en el respaldo: las de la compañía y las que
     * crearon, aprobaron o contabilizaron algo. Solo nombre y correo.
     *
     * @return list<array{id: int, correo: string, nombre: string}>
     */
    private function people(BackupOptions $options): array
    {
        $ids = DB::table('company_user')->where('company_id', $options->company->id)->pluck('user_id')->all();

        foreach ($options->tables() as $table) {
            if (BackupCatalog::form($table) === 'state') {
                continue;
            }

            foreach ($this->schema->references($table) as $column => $target) {
                if ($target === 'users') {
                    $ids = [...$ids, ...$this->query($table, $options)->whereNotNull("{$table}.{$column}")->distinct()->pluck("{$table}.{$column}")->all()];
                }
            }

            if (in_array($table, ['module_permissions', 'document_type_permissions'], true)) {
                $ids = [...$ids, ...$this->query($table, $options)->where("{$table}.subject_type", 'user')->distinct()->pluck("{$table}.subject_id")->all()];
            }
        }

        return DB::table('users')
            ->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('id')
            ->get(['id', 'email', 'name'])
            ->map(fn ($user) => ['id' => (int) $user->id, 'correo' => $user->email, 'nombre' => $user->name])
            ->all();
    }
}
