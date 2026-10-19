<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Al completar una compañía (BackupMerger): lo que el sistema lleva
 * calculado a partir de los movimientos, para lo que recibió movimientos
 * nuevos. Es lo único que cambia de lo que ya estaba, y no es un dato
 * distinto sino la consecuencia de lo que se agregó:
 *
 * - El siguiente consecutivo de cada tipo de documento y de cada serie:
 *   después del último número que entró. Si no, el próximo asiento chocaría
 *   con uno importado.
 * - Lo aplicado, el saldo y el estado de cada partida que recibió un pago o
 *   una reconciliación, y de las que entraron: la suma de sus aplicaciones,
 *   como ApplyPaymentService (o cerrada, si se reconcilió o se anuló).
 * - Las existencias por almacén, ubicación y lote, y el costo promedio de
 *   cada artículo con kardex nuevo, como BackupState: la última fila del
 *   kardex y la suma de entradas y salidas. Sus series, como las deja el
 *   respaldo (BackupMerger ya comprobó que el kardex sigue desde el mismo
 *   punto).
 * - Lo apartado y lo pedido en camino de las órdenes nuevas pendientes.
 * - El saldo de un rebajo con aplicaciones nuevas, la marca de conciliada de
 *   una línea del estado de cuenta que entró en una conciliación nueva, y la
 *   próxima fecha de un registro programado que generó asientos nuevos.
 */
final class BackupBalances
{
    /** Lo nuevo que se mira. */
    public const WATCH = [
        'journal_entries', 'sales_documents', 'bp_open_items', 'bp_payment_applications', 'bp_open_item_reconciliation_lines',
        'fx_revaluation_runs', 'stock_journals', 'item_warehouses', 'purchase_orders', 'sales_orders',
        'employee_deduction_applications', 'bank_reconciliation_lines',
    ];

    /** De lo que ya estaba, lo que dice el respaldo y hace falta: tabla => columnas. */
    public const STASH = [
        'bp_open_items' => ['last_revaluation_rate'],
        'item_serials' => ['item_id', 'status', 'warehouse_id', 'warehouse_bin_id', 'item_lot_id', 'received_document_id', 'issued_document_id', 'received_at', 'issued_at'],
        'journal_entry_schedules' => ['next_run_date'],
    ];

    /**
     * @param  array<string, list<int>>  $inserted
     * @param  array<string, array<int, true>>  $present
     * @param  array<string, array<int, array<string, mixed>>>  $stash
     */
    public function __construct(
        private readonly Company $company,
        private readonly array $inserted,
        private readonly array $present,
        private readonly array $stash,
    ) {}

    public function apply(): void
    {
        $this->consecutives();
        $this->openItems();
        $this->stock();
        $this->orders();
        $this->deductions();
        $this->statementLines();
        $this->schedules();
    }

    private function consecutives(): void
    {
        $company = $this->company->id;

        foreach (array_unique([...$this->column('journal_entries', 'document_type_id'), ...$this->column('sales_documents', 'document_type_id')]) as $typeId) {
            $current = DB::table('document_types')->where('id', $typeId)->value('next_consecutive');

            if ($current === null) {
                continue;
            }

            $lastEntry = (int) DB::table('journal_entries')->where('company_id', $company)->where('document_type_id', $typeId)->max('document_number');
            // Los últimos diez dígitos del consecutivo de Hacienda son el número.
            $lastSale = (int) DB::table('sales_documents')->where('company_id', $company)->where('document_type_id', $typeId)
                ->whereNotNull('consecutive')->selectRaw('MAX(SUBSTR(consecutive, -10)) as last')->value('last');
            $next = max($lastEntry, $lastSale) + 1;

            if ($next > (int) $current) {
                DB::table('document_types')->where('id', $typeId)->update(['next_consecutive' => $next, 'updated_at' => now()]);
            }
        }

        foreach ($this->column('journal_entries', 'number_series_id') as $seriesId) {
            $last = (int) DB::table('journal_entries')->where('number_series_id', $seriesId)->max('series_number');
            DB::table('document_type_number_series')->where('id', $seriesId)->where('next_number', '<=', $last)->update(['next_number' => $last + 1, 'updated_at' => now()]);
        }
    }

    private function openItems(): void
    {
        $ids = array_values(array_unique([
            ...($this->inserted['bp_open_items'] ?? []),
            ...$this->column('bp_payment_applications', 'open_item_id'),
            ...$this->column('bp_open_item_reconciliation_lines', 'bp_open_item_id'),
        ]));

        foreach (array_chunk($ids, 500) as $chunk) {
            $items = DB::table('bp_open_items')
                ->join('journal_details', 'journal_details.id', '=', 'bp_open_items.origin_journal_detail_id')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
                ->whereIn('bp_open_items.id', $chunk)
                ->get(['bp_open_items.*', 'journal_entries.id as entry_id', 'journal_entries.status as entry_status']);

            $applied = DB::table('bp_payment_applications')->whereIn('open_item_id', $chunk)
                ->groupBy('open_item_id')->selectRaw('open_item_id, SUM(applied_amount) as total')->pluck('total', 'open_item_id');
            // Anulada o reconciliada con otra partida: cerrada sin un pago.
            $closed = BackupState::closedUntil($items, '2999-12-31');

            foreach ($items as $item) {
                $original = bcadd((string) $item->original_amount, '0', 2);
                $appliedAmount = isset($closed[$item->id]) ? $original : bcadd((string) ($applied[$item->id] ?? '0'), '0', 2);
                $balance = bcsub($original, $appliedAmount, 2);
                $status = match (true) {
                    bccomp($balance, '0', 2) <= 0 => 'closed',
                    bccomp($appliedAmount, '0', 2) > 0 => 'partial',
                    default => 'open',
                };

                if (bccomp((string) $item->applied_amount, $appliedAmount, 2) !== 0 || bccomp((string) $item->balance, $balance, 2) !== 0 || $item->status !== $status) {
                    DB::table('bp_open_items')->where('id', $item->id)->update(['applied_amount' => $appliedAmount, 'balance' => $balance, 'status' => $status, 'updated_at' => now()]);
                }
            }
        }

        // Un diferencial cambiario nuevo dejó otro tipo en las partidas que ya estaban.
        if (! empty($this->inserted['fx_revaluation_runs'])) {
            foreach ($this->stash['bp_open_items'] ?? [] as $id => $values) {
                if ($values['last_revaluation_rate'] !== null) {
                    DB::table('bp_open_items')->where('id', $id)->update(['last_revaluation_rate' => $values['last_revaluation_rate']]);
                }
            }
        }
    }

    private function stock(): void
    {
        $company = $this->company->id;
        $serials = [];

        foreach ($this->stash['item_serials'] ?? [] as $id => $values) {
            $serials[(int) $values['item_id']][$id] = array_diff_key($values, ['item_id' => true]);
        }

        foreach ($this->column('stock_journals', 'item_id') as $itemId) {
            $kardex = fn () => DB::table('stock_journals')->where('company_id', $company)->where('item_id', $itemId);

            // Por almacén: la última fila del kardex de cada uno.
            $lastIds = $kardex()->groupBy('warehouse_id')->selectRaw('MAX(id) as id')->pluck('id');

            foreach (DB::table('stock_journals')->whereIn('id', $lastIds)->get(['warehouse_id', 'balance_quantity']) as $row) {
                $this->put('item_warehouses', ['item_id' => $itemId, 'warehouse_id' => $row->warehouse_id], ['on_hand' => $row->balance_quantity]);
            }

            // Por ubicación y por lote: la suma de entradas y salidas.
            foreach ($this->sums($kardex(), ['warehouse_bin_id'], 'warehouse_bin_id') as $row) {
                $this->put('item_bins', ['item_id' => $itemId, 'warehouse_bin_id' => $row->warehouse_bin_id], ['on_hand' => $row->on_hand]);
            }

            foreach ($this->sums($kardex(), ['item_lot_id', 'warehouse_id', 'warehouse_bin_id'], 'item_lot_id') as $row) {
                $this->put('item_lot_stock', ['item_lot_id' => $row->item_lot_id, 'warehouse_id' => $row->warehouse_id, 'warehouse_bin_id' => $row->warehouse_bin_id], ['on_hand' => $row->on_hand]);
            }

            // El costo promedio: el de la última fila.
            $last = $kardex()->orderByDesc('id')->first(['avg_cost_local_after', 'avg_cost_foreign_after']);

            if ($last !== null) {
                DB::table('items')->where('id', $itemId)->update(['avg_cost_local' => $last->avg_cost_local_after, 'avg_cost_foreign' => $last->avg_cost_foreign_after, 'updated_at' => now()]);
            }

            foreach ($serials[$itemId] ?? [] as $id => $values) {
                DB::table('item_serials')->where('id', $id)->update($values + ['updated_at' => now()]);
            }
        }
    }

    /** Lo apartado (pedidos) y lo pedido en camino (compras) de las órdenes nuevas pendientes. */
    private function orders(): void
    {
        $deltas = [];
        $sources = [
            ['sales_orders', 'sales_order_lines', 'sales_order_id', ['open'], 'quantity_invoiced', 'reserved'],
            ['purchase_orders', 'purchase_order_lines', 'purchase_order_id', PurchaseOrder::PENDING_STATUSES, 'quantity_received', 'ordered'],
        ];

        foreach ($sources as [$orders, $lines, $key, $pending, $done, $field]) {
            foreach (array_chunk($this->inserted[$orders] ?? [], 500) as $ids) {
                $rows = DB::table($lines)->join($orders, "{$orders}.id", '=', "{$lines}.{$key}")
                    ->whereIn("{$orders}.id", $ids)->whereIn("{$orders}.status", $pending)
                    ->get(["{$lines}.item_id", "{$lines}.warehouse_id", "{$lines}.quantity", "{$lines}.{$done} as done"]);

                foreach ($rows as $row) {
                    $slot = $row->item_id.'|'.$row->warehouse_id;
                    $deltas[$slot][$field] = bcadd($deltas[$slot][$field] ?? '0', bcsub((string) $row->quantity, (string) $row->done, 6), 6);
                }
            }
        }

        $insertedRows = array_flip($this->inserted['item_warehouses'] ?? []);

        foreach ($deltas as $slot => $delta) {
            [$itemId, $warehouseId] = array_map('intval', explode('|', $slot));
            $row = DB::table('item_warehouses')->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->first();

            // Entró del respaldo: ya trae lo de sus órdenes.
            if ($row !== null && isset($insertedRows[$row->id])) {
                continue;
            }

            $values = [];

            foreach ($delta as $field => $amount) {
                $values[$field] = bcadd((string) ($row->{$field} ?? '0'), $amount, 6);
            }

            $this->put('item_warehouses', ['item_id' => $itemId, 'warehouse_id' => $warehouseId], $values);
        }
    }

    private function deductions(): void
    {
        foreach ($this->column('employee_deduction_applications', 'employee_deduction_id') as $id) {
            $deduction = DB::table('employee_deductions')->where('id', $id)->first(['original_amount', 'status']);

            if (! isset($this->present['employee_deductions'][$id]) || $deduction?->original_amount === null) {
                continue;
            }

            $applied = bcadd((string) DB::table('employee_deduction_applications')->where('employee_deduction_id', $id)->sum('amount'), '0', 2);
            $balance = bcsub((string) $deduction->original_amount, $applied, 2);

            DB::table('employee_deductions')->where('id', $id)->update([
                'balance' => $balance,
                'status' => bccomp($balance, '0', 2) <= 0 ? 'settled' : $deduction->status,
                'updated_at' => now(),
            ]);
        }
    }

    private function statementLines(): void
    {
        $ids = array_values(array_filter(
            $this->column('bank_reconciliation_lines', 'bank_statement_line_id'),
            fn (int $id) => isset($this->present['bank_statement_lines'][$id]),
        ));

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('bank_statement_lines')->whereIn('id', $chunk)->update(['matched' => true, 'updated_at' => now()]);
        }
    }

    private function schedules(): void
    {
        foreach ($this->column('journal_entries', 'schedule_id') as $id) {
            $next = $this->stash['journal_entry_schedules'][$id]['next_run_date'] ?? null;

            if ($next !== null) {
                DB::table('journal_entry_schedules')->where('id', $id)->where('next_run_date', '<', $next)->update(['next_run_date' => $next, 'updated_at' => now()]);
            }
        }
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    /**
     * Los valores distintos de una columna en las filas nuevas de una tabla.
     *
     * @return list<int>
     */
    private function column(string $table, string $column): array
    {
        $values = [];

        foreach (array_chunk($this->inserted[$table] ?? [], 1000) as $ids) {
            foreach (DB::table($table)->whereIn('id', $ids)->whereNotNull($column)->distinct()->pluck($column) as $value) {
                $values[(int) $value] = true;
            }
        }

        return array_keys($values);
    }

    /** @param  list<string>  $keys */
    private function sums($query, array $keys, string $required)
    {
        return $query->whereNotNull($required)
            ->groupBy($keys)
            ->selectRaw(implode(', ', $keys).", SUM(CASE WHEN direction = 'in' THEN quantity WHEN direction = 'out' THEN -quantity ELSE 0 END) as on_hand")
            ->get();
    }

    /**
     * La fila con esa llave (un vacío, como vacío): se actualiza si está, se
     * crea si no.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $values
     */
    private function put(string $table, array $where, array $values): void
    {
        $query = DB::table($table);

        foreach ($where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        if ($query->exists()) {
            $query->update($values + ['updated_at' => now()]);

            return;
        }

        DB::table($table)->insert($where + $values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
