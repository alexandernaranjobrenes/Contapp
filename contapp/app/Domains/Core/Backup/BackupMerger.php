<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Completar una compañía que ya existe con lo que le falta de un respaldo
 * (CLAUDE.md secc. 37). BackupImporter lee y traduce cada fila; esto decide
 * si ya está (BackupCatalog: OWNERS, KEYS, las llaves únicas) y lleva la
 * cuenta.
 *
 * - Lo que ya está no se toca, aunque tenga otros datos: se cuenta.
 * - Un documento (asiento, factura, movimiento, planilla…) que ya está pero
 *   con otros datos —en otra etapa, o con el mismo número y otro contenido—
 *   impide completarla: traer lo que generó en el respaldo lo duplicaría o lo
 *   dejaría a medias. Se dice cuál.
 * - El kardex se completa solo hacia adelante: lo que le falta a un artículo
 *   tiene que ir después de lo último que ya tiene, y arrancar desde la misma
 *   existencia y el mismo costo promedio. Si no, cambiaría el costo de
 *   salidas ya contabilizadas.
 * - Los saldos iniciales de un respaldo «desde» una fecha entran solo si la
 *   compañía no tiene movimientos antes de esa fecha: si los tiene, ya tiene
 *   esos saldos.
 * - Lo que el sistema lleva calculado (saldos de partidas, existencias,
 *   consecutivos…) se recalcula al final para lo que recibió movimientos
 *   nuevos (BackupBalances).
 */
final class BackupMerger
{
    private const CONFLICT_LIMIT = 30;

    /** Lo que BackupOpening arma con id negativo, y de qué saldos es. */
    private const OPENING = [
        'document_types' => 'accounting',
        'journal_entries' => 'accounting',
        'journal_details' => 'accounting',
        'stock_journals' => 'inventory',
        'vacation_movements' => 'payroll',
    ];

    private const STATUS_WORDS = [
        'draft' => 'en borrador', 'posted' => 'contabilizado', 'voided' => 'anulado', 'open' => 'abierto',
        'closed' => 'cerrado', 'calculated' => 'calculado', 'approved' => 'aprobado', 'in_progress' => 'en curso',
        'completed' => 'terminado', 'partially_received' => 'recibido en parte', 'received' => 'recibido',
        'invoiced' => 'facturado', 'cancelled' => 'cancelado', 'pending' => 'pendiente', 'applied' => 'aplicado',
    ];

    /** @var array<string, array<int|string, int>> el de-para del importador (por referencia) */
    private array $map;

    /** @var array<string, array<int, true>> lo que ya estaba: tabla => [id => true] */
    private array $present = [];

    /** @var array<string, array<int, true>> lo que ya se reconoció por su llave: no se reconoce dos veces */
    private array $claimed = [];

    /** @var array<string, list<object>> las líneas de un documento que ya está, para ir una por una */
    private array $children = [];

    /** @var array<string, int> */
    private array $ordinal = [];

    /** @var array<string, array<int, true>> los saldos iniciales que no entran */
    private array $skippedOpening = [];

    /** @var array{accounting: bool, inventory: bool, payroll: bool} */
    private array $openingAllowed = ['accounting' => false, 'inventory' => false, 'payroll' => false];

    private ?string $from = null;

    private ?string $sourceTaxId = null;

    /** @var array<string, int> */
    private array $added = [];

    /** @var array<string, int> */
    private array $presentCount = [];

    /** @var array<string, int> */
    private array $different = [];

    private int $openingAdded = 0;

    private int $openingSkipped = 0;

    /** @var array<string, true> */
    private array $conflicts = [];

    /** @var array<string, array<int, string>> cómo se nombra cada documento que ya estaba, para sus líneas */
    private array $labels = [];

    /** @var array<string, list<int>> lo nuevo que mira BackupBalances */
    private array $inserted = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $stash = [];

    /** @var array<string, string> «artículo|almacén» => existencia en el respaldo antes de la fila que sigue */
    private array $stockBefore = [];

    /** @var array<int, string> artículo => costo promedio en el respaldo antes de la fila que sigue */
    private array $costBefore = [];

    /** @var array<int|string, true> */
    private array $checkedItems = [];

    /** @var array<string, true> */
    private array $checkedSlots = [];

    /** @var array<string, bool> */
    private array $documents = [];

    /** @var array<string, list<array{0: list<string>, 1: bool}>> */
    private array $keys = [];

    /** @var array<string, true> las tablas de las que cuelga algo que es parte de un documento */
    private array $ownerTables = [];

    /** @var array<string, array<int, string>> */
    private array $codes = [];

    public function __construct(
        private readonly BackupSchema $schema,
        private readonly Company $company,
        array &$map,
    ) {
        $this->map = &$map;

        foreach (array_keys(BackupCatalog::TABLES) as $table) {
            foreach (BackupCatalog::owners($table) as $column) {
                if ($target = $this->schema->references($table)[$column] ?? null) {
                    $this->ownerTables[$target] = true;
                }
            }
        }
    }

    /** El encabezado del respaldo: la moneda, y si entran los saldos iniciales. */
    public function start(array $manifest): void
    {
        $source = $manifest['compania'] ?? [];
        $this->sourceTaxId = $source['tax_id'] ?? null;
        $this->from = $manifest['periodo']['desde'] ?? null;

        if ((int) ($source['local_currency_id'] ?? 0) !== (int) $this->company->local_currency_id) {
            $this->conflict('La moneda local del respaldo ('.$this->currency($source['local_currency_id'] ?? null).') no es la de esta compañía ('
                .$this->currency($this->company->local_currency_id).'): sus montos no se pueden sumar.');
        }

        if ($this->from !== null) {
            $id = $this->company->id;
            $this->openingAllowed = [
                'accounting' => ! DB::table('journal_entries')->where('company_id', $id)->whereIn('status', ['posted', 'voided'])->where('posting_date', '<', $this->from)->exists(),
                'inventory' => ! DB::table('stock_journals')->where('company_id', $id)->where('posting_date', '<', $this->from)->exists(),
                'payroll' => ! DB::table('vacation_movements')->where('company_id', $id)->where('movement_date', '<', $this->from)->exists(),
            ];
        }
    }

    /** Un saldo inicial del respaldo que no entra: la compañía ya tiene movimientos antes del período. */
    public function skipsOpening(string $table, array $row): bool
    {
        $domain = self::OPENING[$table] ?? null;

        if ($domain === null || (int) $row['id'] >= 0 || $this->openingAllowed[$domain]) {
            return false;
        }

        $this->skippedOpening[$table][(int) $row['id']] = true;

        if ($table !== 'document_types') {
            $this->openingSkipped++;
        }

        return true;
    }

    /** Lo que un saldo inicial que no entra dice del kardex: desde dónde sigue el respaldo. */
    public function observe(string $table, array $values): void
    {
        if ($table === 'stock_journals' && $values['item_id'] !== null && $values['warehouse_id'] !== null) {
            $this->observeStock($values);
        }
    }

    /**
     * Qué es esta fila del respaldo en la compañía: el id de la que ya está,
     * null si falta (se agrega), o false si ya está su documento pero no
     * tiene esta línea (a un documento que ya está no se le agrega nada).
     *
     * @param  array<string, mixed>  $row  la fila como viene en el respaldo
     * @param  array<string, mixed>  $values  la fila con sus referencias traducidas
     * @param  list<string>  $unresolved  las columnas cuya referencia no se pudo traducir
     */
    public function find(string $table, array $row, array $values, array $unresolved): int|false|null
    {
        $found = $this->identify($table, $row, $values, $unresolved);

        if ($table === 'stock_journals' && $values['item_id'] !== null && $values['warehouse_id'] !== null) {
            if ($found === null) {
                $this->checkStock($values);
            }

            $this->observeStock($values);
        }

        if ($found !== null) {
            $this->presentCount[$table] = ($this->presentCount[$table] ?? 0) + 1;
        }

        if (is_int($found)) {
            $this->present[$table][$found] = true;

            if (isset(BackupBalances::STASH[$table])) {
                $this->stash[$table][$found] = array_intersect_key($values, array_flip(BackupBalances::STASH[$table]));
            }
        }

        return $found;
    }

    /** Una fila que se agregó. */
    public function inserted(string $table, int $id, bool $opening): void
    {
        $this->added[$table] = ($this->added[$table] ?? 0) + 1;

        if ($opening && isset(self::OPENING[$table]) && $table !== 'document_types') {
            $this->openingAdded++;
        }

        if (in_array($table, BackupBalances::WATCH, true)) {
            $this->inserted[$table][] = $id;
        }
    }

    public function isPresent(string $table, int $id): bool
    {
        return isset($this->present[$table][$id]);
    }

    /** Lo calculado, para lo que recibió movimientos nuevos. */
    public function finish(): void
    {
        (new BackupBalances($this->company, $this->inserted, $this->present, $this->stash))->apply();
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * Lo que le faltaba, lo que ya tenía, lo que tiene distinto y lo que
     * impide completarla.
     *
     * @param  array<string, int>  $dropped
     * @param  array{added: list<string>, missing: list<string>, no_quota: list<string>}  $people
     */
    public function report(array $dropped, array $people): array
    {
        $tables = array_unique([...array_keys($this->added), ...array_keys($this->presentCount), ...array_keys($dropped)]);
        sort($tables);
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table] = [
                'added' => $this->added[$table] ?? 0,
                'present' => $this->presentCount[$table] ?? 0,
                'different' => $this->different[$table] ?? 0,
                'dropped' => $dropped[$table] ?? 0,
            ];
        }

        $tax = $this->company->tax_id;

        return [
            'company' => ['id' => $this->company->id, 'name' => $this->company->trade_name ?: $this->company->legal_name, 'tax_id' => $tax],
            // Otra cédula: probablemente es otra compañía. No lo impide (se
            // puede querer pasar un catálogo), pero se avisa.
            'other_tax_id' => $tax && $this->sourceTaxId && preg_replace('/\D/', '', $tax) !== preg_replace('/\D/', '', $this->sourceTaxId) ? $this->sourceTaxId : null,
            'tables' => $rows,
            'totals' => [
                'added' => array_sum(array_column($rows, 'added')),
                'present' => array_sum(array_column($rows, 'present')),
                'different' => array_sum(array_column($rows, 'different')),
                'dropped' => array_sum(array_column($rows, 'dropped')),
            ],
            'opening' => $this->from === null ? null : [
                'date' => date('d/m/Y', strtotime($this->from.' -1 day')),
                'from' => date('d/m/Y', strtotime($this->from)),
                'added' => $this->openingAdded,
                'skipped' => $this->openingSkipped,
            ],
            'conflicts' => array_slice(array_keys($this->conflicts), 0, self::CONFLICT_LIMIT),
            'conflicts_total' => count($this->conflicts),
            'people' => $people,
        ];
    }

    /** Lo que se le dice a quien confirmó, si al importar apareció algo que lo impide. */
    public static function conflictMessage(array $report): string
    {
        $more = $report['conflicts_total'] - 1;

        return 'No se completó la compañía, y no se guardó nada. '.($report['conflicts'][0] ?? '')
            .($more > 0 ? " Y {$more} ".($more === 1 ? 'cosa más' : 'cosas más').': volvé a comparar para verlas.' : '');
    }

    // ── Cómo se reconoce una fila ───────────────────────────────────────

    private function identify(string $table, array $row, array $values, array $unresolved): int|false|null
    {
        $references = $this->schema->references($table);

        foreach (BackupCatalog::owners($table) as $column) {
            $old = $row[$column] ?? null;
            $target = $references[$column] ?? null;

            if ($old === null || $target === null || isset($this->skippedOpening[$target][(int) $old])) {
                continue;
            }

            $owner = $this->map[$target][(int) $old] ?? null;

            if ($owner === null) {
                continue;
            }

            // Su documento es nuevo: ella también. Solo se revisa que no
            // choque con una llave única (una partida ya reconciliada).
            if (! isset($this->present[$target][$owner])) {
                return $this->schema->uniqueKeys($table) !== [] ? $this->byKey($table, $values, $unresolved, uniqueOnly: true) : null;
            }

            return $this->counterpart($table, $column, $owner, $target, $values, $unresolved);
        }

        return $this->byKey($table, $values, $unresolved);
    }

    /**
     * La línea de un documento que ya está: la que ocupa el mismo lugar entre
     * las suyas (las dos van en el orden en que se registraron).
     */
    private function counterpart(string $table, string $column, int $owner, string $ownerTable, array $values, array $unresolved): int|false
    {
        $key = "{$table}|{$column}|{$owner}";

        if (! isset($this->ordinal[$key])) {
            $this->children[$key] = DB::table($table)->where($column, $owner)->orderBy('id')->get()->all();
            $this->ordinal[$key] = 0;
        }

        $match = $this->children[$key][$this->ordinal[$key]++] ?? null;

        // Ya se recorrieron todas: no hace falta tenerlas.
        if ($this->ordinal[$key] >= count($this->children[$key] ?? [])) {
            unset($this->children[$key]);
        }

        if ($match === null) {
            return false;
        }

        $id = (int) $match->id;
        $label = BackupCatalog::form($table) === 'movement' ? $this->describe($table, $values) : ($this->labels[$ownerTable][$owner] ?? null);
        $this->compare($table, $values, $match, $unresolved, $label);

        if ($label !== null && isset($this->ownerTables[$table])) {
            $this->labels[$table][$id] = $label;
        }

        return $id;
    }

    /** La que tiene la misma llave: una llave única de la base, o la de KEYS. */
    private function byKey(string $table, array $values, array $unresolved, bool $uniqueOnly = false): ?int
    {
        foreach ($this->keysOf($table) as [$columns, $strict]) {
            if ($uniqueOnly && ! $strict) {
                continue;
            }

            $query = DB::table($table);

            foreach ($columns as $column) {
                $value = $values[$column] ?? null;

                if ($value === null) {
                    // Una llave única con un vacío no identifica nada (la
                    // base admite repetidos); una referencia que no se pudo
                    // traducir, tampoco.
                    if ($strict || in_array($column, $unresolved, true)) {
                        continue 2;
                    }

                    $query->whereNull($column);
                } else {
                    $query->where($column, $value);
                }
            }

            if (! in_array('company_id', $columns, true) && $this->schema->hasColumn($table, 'company_id')) {
                $query->where('company_id', $this->company->id);
            }

            foreach ($query->orderBy('id')->get() as $candidate) {
                $id = (int) $candidate->id;

                if (isset($this->claimed[$table][$id])) {
                    continue;
                }

                $this->claimed[$table][$id] = true;
                $label = $this->isDocument($table) ? $this->describe($table, $values) : null;
                $this->compare($table, $values, $candidate, $unresolved, $label);

                if ($label !== null && isset($this->ownerTables[$table])) {
                    $this->labels[$table][$id] = $label;
                }

                return $id;
            }
        }

        return null;
    }

    /** @return list<array{0: list<string>, 1: bool}> [columnas, si es una llave única de la base] */
    private function keysOf(string $table): array
    {
        return $this->keys[$table] ??= [
            ...array_map(fn (array $columns) => [$columns, true], $this->schema->uniqueKeys($table)),
            ...(isset(BackupCatalog::KEYS[$table]) ? [[BackupCatalog::KEYS[$table], false]] : []),
        ];
    }

    // ── Lo que ya está con otros datos ──────────────────────────────────

    /**
     * Si lo que ya está es igual a lo que trae el respaldo. Un documento
     * distinto impide completar la compañía; cualquier otra cosa distinta
     * solo se cuenta, y queda la de acá.
     */
    private function compare(string $table, array $values, object $target, array $unresolved, ?string $label): void
    {
        if (BackupCatalog::form($table) === 'state' || $table === 'audit_logs') {
            return;
        }

        $skip = array_flip(['id', 'created_at', 'updated_at', ...$unresolved, ...(BackupCatalog::DERIVED[$table] ?? [])]);

        // Quién lo hizo: la persona puede no tener cuenta acá.
        foreach ($this->schema->references($table) as $column => $to) {
            if ($to === 'users') {
                $skip[$column] = true;
            }
        }

        $differs = [];

        foreach ($values as $column => $value) {
            if (! isset($skip[$column]) && property_exists($target, $column) && ! self::same($value, $target->{$column})) {
                $differs[] = $column;
            }
        }

        if ($differs === []) {
            return;
        }

        if ($label !== null && $this->isDocument($table)) {
            $stage = in_array('status', $differs, true) && isset($values['status'])
                ? ' (acá está '.$this->word($target->status).'; en el respaldo, '.$this->word($values['status']).')'
                : '';

            $this->conflict("{$label} ya está en esta compañía con otros datos{$stage}.");

            return;
        }

        $this->different[$table] = ($this->different[$table] ?? 0) + 1;
    }

    /** Un movimiento, una de sus líneas, o lo que se trata como documento. */
    private function isDocument(string $table): bool
    {
        if (! isset($this->documents[$table])) {
            $owners = BackupCatalog::owners($table);
            $ownerTable = $owners !== [] ? ($this->schema->references($table)[$owners[0]] ?? null) : null;

            $this->documents[$table] = match (true) {
                in_array($table, BackupCatalog::DOCUMENTS, true) => true,
                BackupCatalog::form($table) === 'movement' => $table !== 'audit_logs',
                BackupCatalog::form($table) === 'state' => false,
                $ownerTable !== null => $this->isDocument($ownerTable),
                default => false,
            };
        }

        return $this->documents[$table];
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return ($a === null || $a === '') && ($b === null || $b === '');
        }

        $a = is_bool($a) ? (int) $a : $a;
        $b = is_bool($b) ? (int) $b : $b;

        if (is_numeric($a) && is_numeric($b)) {
            return bccomp(self::decimal($a), self::decimal($b), 6) === 0;
        }

        $a = (string) $a;
        $b = (string) $b;

        if ($a === $b) {
            return true;
        }

        // Una fecha con o sin la hora en cero.
        $date = '/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/';

        if (preg_match($date, $a) && preg_match($date, $b)) {
            return substr($a, 0, 10) === substr($b, 0, 10);
        }

        // Un JSON con lo mismo, aunque esté escrito distinto.
        if (in_array($a[0], ['{', '['], true)) {
            $left = json_decode($a, true);

            return $left !== null && $left == json_decode($b, true);
        }

        return false;
    }

    private static function decimal(mixed $value): string
    {
        $value = ltrim(trim((string) $value), '+');

        return preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : number_format((float) $value, 6, '.', '');
    }

    private function conflict(string $text): void
    {
        $this->conflicts[$text] = true;
    }

    // ── El kardex, solo hacia adelante ──────────────────────────────────

    /**
     * La primera fila que falta de un artículo (y de cada almacén): tiene que
     * ir después de lo último que la compañía ya tiene de él, y la compañía
     * tiene que estar donde el respaldo estaba antes de esa fila.
     */
    private function checkStock(array $values): void
    {
        $item = (int) $values['item_id'];
        $warehouse = (int) $values['warehouse_id'];
        $date = substr((string) $values['posting_date'], 0, 10);
        $kardex = fn () => DB::table('stock_journals')->where('company_id', $this->company->id)->where('item_id', $item);

        if (! isset($this->checkedItems[$item])) {
            $this->checkedItems[$item] = true;
            $lastDate = $kardex()->max('posting_date');

            if ($lastDate !== null && substr((string) $lastDate, 0, 10) > $date) {
                $this->conflict("{$this->code('items', $item)}: lo que le falta del kardex es del ".self::day($date)
                    .', antes del último movimiento que ya tiene acá ('.self::day((string) $lastDate).'). Agregarlo cambiaría el costo de salidas ya contabilizadas.');
            }

            $cost = (string) ($kardex()->orderByDesc('id')->value('avg_cost_local_after') ?? '0');
            $before = $this->costBefore[$item] ?? '0';

            if (! self::same($cost, $before)) {
                $this->conflict("{$this->code('items', $item)}: el costo promedio de acá (".self::number($cost, 2).') no es del que sigue el respaldo ('.self::number($before, 2).').');
            }
        }

        $slot = "{$item}|{$warehouse}";

        if (! isset($this->checkedSlots[$slot])) {
            $this->checkedSlots[$slot] = true;
            $onHand = (string) ($kardex()->where('warehouse_id', $warehouse)->orderByDesc('id')->value('balance_quantity') ?? '0');
            $before = $this->stockBefore[$slot] ?? '0';

            if (! self::same($onHand, $before)) {
                $this->conflict("{$this->code('items', $item)} en {$this->code('warehouses', $warehouse)}: acá hay ".self::number($onHand, 2)
                    .' y el respaldo sigue desde '.self::number($before, 2).'.');
            }
        }
    }

    private function observeStock(array $values): void
    {
        $this->stockBefore[$values['item_id'].'|'.$values['warehouse_id']] = (string) $values['balance_quantity'];
        $this->costBefore[(int) $values['item_id']] = (string) $values['avg_cost_local_after'];
    }

    // ── Nombres ─────────────────────────────────────────────────────────

    /** «El asiento FVE-12 del 05/01/2026». */
    private function describe(string $table, array $values): string
    {
        $number = match (true) {
            isset($values['document_number'], $values['document_type_id']) => $this->code('document_types', (int) $values['document_type_id']).'-'.$values['document_number'],
            ! empty($values['consecutive']) => (string) $values['consecutive'],
            ! empty($values['number']) => (string) $values['number'],
            default => null,
        };

        $column = BackupCatalog::option($table, 'date');
        $date = $column !== null && ! empty($values[$column]) ? self::day((string) $values[$column]) : null;

        return (BackupCatalog::LABELS[$table] ?? 'El documento').($number !== null ? " {$number}" : '').($date !== null ? " del {$date}" : '');
    }

    private function code(string $table, int $id): string
    {
        return $this->codes[$table][$id] ??= (string) (DB::table($table)->where('id', $id)->value('code') ?? "#{$id}");
    }

    private function currency(mixed $id): string
    {
        return $id === null ? '—' : (string) (DB::table('currencies')->where('id', $id)->value('code') ?? "#{$id}");
    }

    private function word(mixed $status): string
    {
        return '«'.(self::STATUS_WORDS[(string) $status] ?? (string) $status).'»';
    }

    private static function day(string $date): string
    {
        return date('d/m/Y', strtotime(substr($date, 0, 10)));
    }

    private static function number(string $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, ',', ' ');
    }
}
