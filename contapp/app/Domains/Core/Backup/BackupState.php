<?php

namespace App\Domains\Core\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El estado al cierre del período: las partidas abiertas, las existencias,
 * las series, el costo promedio y el saldo de las deducciones.
 *
 * - «Hasta» hoy: como están.
 * - «Hasta» una fecha anterior: como estaban ese día, calculado de los
 *   movimientos (el kardex, las aplicaciones de pagos…). Si no, la compañía
 *   importada tendría las existencias de hoy con los movimientos de antes.
 * - Las partidas que vienen de antes del período (BackupOpening) arrancan
 *   con su saldo a ese día y cuelgan de su línea del asiento de apertura.
 */
final class BackupState
{
    public function __construct(
        private readonly BackupOptions $options,
        private readonly BackupOpening $opening,
    ) {}

    // ── Partidas abiertas ───────────────────────────────────────────────

    /** @return \Generator<array<string, mixed>> */
    public function openItems(): \Generator
    {
        $query = DB::table('bp_open_items')
            ->join('journal_details', 'journal_details.id', '=', 'bp_open_items.origin_journal_detail_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $this->options->company->id)
            ->select(['bp_open_items.*', 'journal_entries.id as entry_id', 'journal_entries.status as entry_status'])
            ->orderBy('bp_open_items.id');

        // Las del período: su asiento está en el respaldo.
        $from = $this->options->fromDate();
        $to = $this->options->toDate();
        $query->where(function ($q) use ($from, $to) {
            $q->where(function ($q) use ($from, $to) {
                $q->when($from, fn ($q) => $q->where('journal_entries.posting_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('journal_entries.posting_date', '<=', $to));
            });

            // Y las que venían abiertas de antes.
            if ($this->opening->openItems() !== []) {
                $q->orWhereIn('bp_open_items.id', array_keys($this->opening->openItems()));
            }
        });

        // De a 500: lo aplicado y lo cerrado se consulta por tanda, no por partida.
        $batch = [];

        foreach ($query->lazyById(500, 'bp_open_items.id', 'id') as $item) {
            $batch[] = $item;

            if (count($batch) === 500) {
                yield from $this->openItemRows(collect($batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->openItemRows(collect($batch));
        }
    }

    /** @return list<array<string, mixed>> */
    private function openItemRows(Collection $items): array
    {
        $to = $this->options->toDate();
        $applied = $to ? self::appliedUntil($items->pluck('id')->all(), $to, $this->options->fromDate()) : [];
        $closed = $to ? self::closedUntil($items, $to) : [];
        $rows = [];

        foreach ($items as $item) {
            $row = (array) $item;
            unset($row['entry_id'], $row['entry_status']);
            $opening = $this->opening->openItem((int) $item->id);

            if ($opening !== null) {
                // Viene de antes del período: arranca con su saldo a ese día.
                $row['origin_journal_detail_id'] = $opening['detail_id'];
                $row['original_amount'] = $opening['balance'];
                $row['last_revaluation_rate'] = $opening['last_revaluation_rate'] ?? $item->last_revaluation_rate;
            }

            if ($to !== null) {
                $appliedAmount = isset($closed[$item->id]) ? (string) $row['original_amount'] : ($applied[$item->id] ?? '0.00');
                $row['applied_amount'] = $appliedAmount;
                $row['balance'] = bcsub((string) $row['original_amount'], $appliedAmount, 2);
            } elseif ($opening !== null) {
                // Hasta hoy: el saldo es el de hoy; lo aplicado, lo del período.
                $row['applied_amount'] = bcsub((string) $row['original_amount'], (string) $item->balance, 2);
            }

            if ($to !== null || $opening !== null) {
                $row['status'] = match (true) {
                    bccomp((string) $row['balance'], '0', 2) <= 0 => 'closed',
                    bccomp((string) $row['applied_amount'], '0', 2) > 0 => 'partial',
                    default => 'open',
                };
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Lo aplicado a cada partida hasta ese día (y desde $from, si se da).
     *
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    public static function appliedUntil(array $itemIds, string $day, ?string $from = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('bp_payment_applications')
            ->whereIn('open_item_id', $itemIds)
            ->where('applied_date', '<=', $day)
            ->when($from, fn ($q) => $q->where('applied_date', '>=', $from))
            ->groupBy('open_item_id')
            ->selectRaw('open_item_id, SUM(applied_amount) as total')
            ->pluck('total', 'open_item_id')
            ->map(fn ($total) => bcadd((string) $total, '0', 2))
            ->all();
    }

    /**
     * Las partidas cerradas hasta ese día sin un pago: su asiento se anuló o
     * se reconciliaron con otra partida.
     *
     * @param  Collection<int, object>  $items  con id, entry_id y entry_status
     * @return array<int, true>
     */
    public static function closedUntil(Collection $items, string $day): array
    {
        $closed = [];
        $voided = $items->where('entry_status', 'voided')->pluck('entry_id')->unique()->values()->all();

        if ($voided !== []) {
            $reversed = DB::table('journal_entries')
                ->whereIn('reversal_of_id', $voided)
                ->where('posting_date', '<=', $day)
                ->pluck('reversal_of_id')
                ->flip();

            foreach ($items as $item) {
                if ($item->entry_status === 'voided' && isset($reversed[$item->entry_id])) {
                    $closed[$item->id] = true;
                }
            }
        }

        $netted = DB::table('bp_open_item_reconciliation_lines')
            ->join('bp_open_item_reconciliations', 'bp_open_item_reconciliations.id', '=', 'bp_open_item_reconciliation_lines.bp_open_item_reconciliation_id')
            ->whereIn('bp_open_item_reconciliation_lines.bp_open_item_id', $items->pluck('id')->all())
            ->where('bp_open_item_reconciliations.reconciled_at', '<', self::nextDay($day))
            ->pluck('bp_open_item_reconciliation_lines.bp_open_item_id');

        foreach ($netted as $id) {
            $closed[(int) $id] = true;
        }

        return $closed;
    }

    // ── Inventario ──────────────────────────────────────────────────────

    /**
     * Las filas de una tabla de existencias. Hasta hoy, como están; hasta
     * una fecha anterior, calculadas del kardex a ese día.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function stock(string $table): \Generator
    {
        $to = $this->options->toDate();
        $company = $this->options->company->id;
        $items = DB::table('items')->where('company_id', $company)->select('id');

        if ($to === null) {
            $query = match ($table) {
                'item_lot_stock' => DB::table($table)->whereIn('item_lot_id', DB::table('item_lots')->whereIn('item_id', $items)->select('id')),
                default => DB::table($table)->whereIn('item_id', $items),
            };

            foreach ($query->lazyById(1000) as $row) {
                yield (array) $row;
            }

            return;
        }

        yield from match ($table) {
            'item_warehouses' => $this->warehousesAt($to, $items),
            'item_bins' => $this->groupedAt($to, 'item_bins', ['item_id', 'warehouse_bin_id']),
            'item_lot_stock' => $this->groupedAt($to, 'item_lot_stock', ['item_lot_id', 'warehouse_id', 'warehouse_bin_id']),
            'item_serials' => $this->serialsAt($to, $items),
        };
    }

    /** @return \Generator<array<string, mixed>> */
    private function warehousesAt(string $to, $items): \Generator
    {
        $balances = $this->lastJournalRows($to)->mapWithKeys(fn ($row) => [$row->item_id.'|'.$row->warehouse_id => $row->balance_quantity]);

        foreach (DB::table('item_warehouses')->whereIn('item_id', $items)->lazyById(1000) as $row) {
            $row = (array) $row;
            $row['on_hand'] = (string) ($balances[$row['item_id'].'|'.$row['warehouse_id']] ?? '0');

            yield $row;
        }
    }

    /**
     * Lo que había por ubicación o por lote: la suma de entradas y salidas
     * del kardex hasta ese día.
     *
     * @param  list<string>  $keys
     * @return \Generator<array<string, mixed>>
     */
    private function groupedAt(string $to, string $table, array $keys): \Generator
    {
        $sourceKeys = array_map(fn (string $key) => $key, $keys);
        $existing = DB::table($table)
            ->when($table === 'item_bins', fn ($q) => $q->whereIn('item_id', DB::table('items')->where('company_id', $this->options->company->id)->select('id')))
            ->when($table === 'item_lot_stock', fn ($q) => $q->whereIn('item_lot_id', DB::table('item_lots')->whereIn('item_id', DB::table('items')->where('company_id', $this->options->company->id)->select('id'))->select('id')))
            ->get()
            ->keyBy(fn ($row) => implode('|', array_map(fn ($k) => (string) ($row->{$k} ?? ''), $keys)));

        $required = $table === 'item_bins' ? 'warehouse_bin_id' : 'item_lot_id';
        $groups = DB::table('stock_journals')
            ->where('company_id', $this->options->company->id)
            ->where('posting_date', '<=', $to)
            ->whereNotNull($required)
            ->groupBy($sourceKeys)
            ->selectRaw(implode(', ', $sourceKeys).", SUM(CASE WHEN direction = 'in' THEN quantity WHEN direction = 'out' THEN -quantity ELSE 0 END) as on_hand")
            ->get();

        $synthetic = -1;
        $now = now()->format('Y-m-d H:i:s');

        foreach ($groups as $group) {
            $key = implode('|', array_map(fn ($k) => (string) ($group->{$k} ?? ''), $keys));
            $row = isset($existing[$key]) ? (array) $existing[$key] : ['id' => $synthetic--, 'created_at' => $now, 'updated_at' => $now];

            foreach ($keys as $k) {
                $row[$k] = $group->{$k};
            }

            $row['on_hand'] = (string) $group->on_hand;

            yield $row;
        }
    }

    /** @return \Generator<array<string, mixed>> */
    private function serialsAt(string $to, $items): \Generator
    {
        $query = DB::table('item_serials')
            ->whereIn('item_id', $items)
            ->where(fn ($q) => $q->whereNull('received_at')->orWhere('received_at', '<=', $to));

        foreach ($query->lazyById(1000) as $row) {
            $row = (array) $row;

            // Entregada después: ese día seguía en existencia.
            if ($row['status'] === 'issued' && $row['issued_at'] !== null && $row['issued_at'] > $to) {
                $row['status'] = 'in_stock';
                $row['issued_at'] = null;
                $row['issued_document_id'] = null;
            }

            yield $row;
        }
    }

    /** @return Collection<int, object> la última fila del kardex de cada artículo y almacén hasta ese día */
    public function lastJournalRows(string $to): Collection
    {
        $lastIds = DB::table('stock_journals')
            ->where('company_id', $this->options->company->id)
            ->where('posting_date', '<=', $to)
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        return DB::table('stock_journals')->whereIn('id', $lastIds)->get();
    }

    // ── Ajustes de filas de catálogo ────────────────────────────────────

    /**
     * El costo promedio de cada artículo hasta ese día (el de la última fila
     * de su kardex) y el saldo de cada deducción.
     *
     * @return array<string, mixed>
     */
    public function adjust(string $table, array $row): array
    {
        $to = $this->options->toDate();

        if ($to === null) {
            return $row;
        }

        if ($table === 'items') {
            $last = $this->itemCosts($to)[$row['id']] ?? null;

            if ($last !== null) {
                $row['avg_cost_local'] = (string) $last->avg_cost_local_after;
                $row['avg_cost_foreign'] = (string) $last->avg_cost_foreign_after;
            }
        }

        if ($table === 'employee_deductions' && $row['original_amount'] !== null) {
            $applied = DB::table('employee_deduction_applications')
                ->where('employee_deduction_id', $row['id'])
                ->where('applied_on', '<=', $to)
                ->sum('amount');
            $row['balance'] = bcsub((string) $row['original_amount'], bcadd((string) $applied, '0', 2), 2);
        }

        return $row;
    }

    /** @var array<int, object>|null */
    private ?array $itemCosts = null;

    /** @return array<int, object> */
    private function itemCosts(string $to): array
    {
        if ($this->itemCosts === null) {
            $lastIds = DB::table('stock_journals')
                ->where('company_id', $this->options->company->id)
                ->where('posting_date', '<=', $to)
                ->groupBy('item_id')
                ->selectRaw('MAX(id) as id')
                ->pluck('id');

            $this->itemCosts = DB::table('stock_journals')->whereIn('id', $lastIds)->get()->keyBy('item_id')->all();
        }

        return $this->itemCosts;
    }

    public static function nextDay(string $day): string
    {
        return date('Y-m-d', strtotime($day.' +1 day'));
    }
}
