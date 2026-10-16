<?php

namespace App\Domains\Core\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los saldos al día anterior al período, para un respaldo «desde» una fecha.
 *
 * Sin esto, la compañía importada arrancaría en cero y todo lo del período
 * saldría mal. Se arma con filas nuevas (de id negativo) en el mismo
 * vocabulario del respaldo, así la importación no las trata distinto:
 *
 * - Un asiento de apertura (tipo APE, contabilizado el día anterior al
 *   período) con el saldo de cada cuenta por socio y moneda, como lo calcula
 *   el balance de comprobación: lo contabilizado y lo anulado antes de esa
 *   fecha. Las cuentas de resultados quedan con lo acumulado del año: así el
 *   estado de resultados del año sigue dando lo mismo.
 * - Cada partida abierta a esa fecha con su saldo, con su propia línea en ese
 *   asiento (su vencimiento y su documento), para que se pueda seguir
 *   cobrando o pagando.
 * - Las existencias y el costo promedio de cada artículo en cada almacén
 *   (por lote y ubicación), como primeras filas del kardex.
 * - El saldo de vacaciones de cada empleado, como un ajuste.
 */
final class BackupOpening
{
    /** @var array<string, list<array<string, mixed>>> tabla => filas nuevas */
    private array $rows = [];

    /** @var array<int, array{detail_id: int, balance: string, last_revaluation_rate: ?string}> partida => su apertura */
    private array $openItems = [];

    /** @var array<int, array<string, mixed>> documento => columnas que cambian */
    private array $documentTypeChanges = [];

    private int $nextId = -1;

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly BackupOptions $options) {}

    public static function build(BackupOptions $options): self
    {
        $opening = new self($options);

        if ($options->from !== null) {
            $opening->buildJournal();

            if ($options->hasSection('inventory')) {
                $opening->buildStock();
            }

            if ($options->hasSection('payroll')) {
                $opening->buildVacations();
            }
        }

        return $opening;
    }

    /** @return list<array<string, mixed>> */
    public function rowsFor(string $table): array
    {
        return $this->rows[$table] ?? [];
    }

    /** @return array{detail_id: int, balance: string, last_revaluation_rate: ?string}|null */
    public function openItem(int $id): ?array
    {
        return $this->openItems[$id] ?? null;
    }

    /** @return array<int, array{detail_id: int, balance: string, last_revaluation_rate: ?string}> */
    public function openItems(): array
    {
        return $this->openItems;
    }

    /** @return array<string, mixed> */
    public function documentTypeChanges(int $id): array
    {
        return $this->documentTypeChanges[$id] ?? [];
    }

    /** @return array<string, int> lo que se armó: cuentas, partidas, artículos, empleados */
    public function counts(): array
    {
        return $this->counts;
    }

    private function companyId(): int
    {
        return $this->options->company->id;
    }

    private function date(): Carbon
    {
        return $this->options->openingDate();
    }

    // ── El asiento de apertura ──────────────────────────────────────────

    private function buildJournal(): void
    {
        $before = $this->options->fromDate();

        $groups = DB::table('journal_details')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $this->companyId())
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->where('journal_entries.posting_date', '<', $before)
            ->groupBy('journal_details.account_id', 'journal_details.business_partner_id', 'journal_details.currency_id')
            ->selectRaw('journal_details.account_id, journal_details.business_partner_id, journal_details.currency_id,
                SUM(journal_details.debit_local) - SUM(journal_details.credit_local) as net_local,
                SUM(journal_details.debit_foreign) - SUM(journal_details.credit_foreign) as net_foreign,
                SUM(journal_details.debit_system) - SUM(journal_details.credit_system) as net_system')
            ->orderBy('journal_details.account_id')
            ->get();

        $items = $this->openItemsAtOpening();
        $itemsByGroup = $items->groupBy(fn ($item) => $item->account_id.'|'.($item->business_partner_id ?? '').'|'.$item->currency_id);

        $lines = [];

        foreach ($groups as $group) {
            $key = $group->account_id.'|'.($group->business_partner_id ?? '').'|'.$group->currency_id;
            $remaining = ['local' => $this->money($group->net_local), 'foreign' => $this->money($group->net_foreign), 'system' => $this->money($group->net_system)];
            $ratio = bccomp($remaining['local'], '0', 2) !== 0 ? bcdiv($remaining['system'], $remaining['local'], 10) : '0';

            // Una línea por partida abierta, con su documento y vencimiento.
            foreach ($itemsByGroup->get($key, collect()) as $item) {
                $sign = $item->debit_side ? '1' : '-1';
                $isLocal = (int) $item->currency_id === (int) $this->options->company->local_currency_id;
                $rate = $item->last_revaluation_rate ?? $item->exchange_rate_lc_fc ?? '1';
                $local = $isLocal ? $item->balance : $this->money(bcmul($item->balance, (string) $rate, 4));
                $net = [
                    'local' => bcmul($local, $sign, 2),
                    'foreign' => $isLocal ? '0.00' : bcmul($item->balance, $sign, 2),
                    'system' => $this->money(bcmul(bcmul($local, $sign, 2), $ratio, 4)),
                ];

                $detailId = $this->nextId();
                $lines[] = $this->line($detailId, $group, $net, [
                    'description' => "Saldo de {$item->document_type_code}-{$item->document_number}",
                    'due_date' => $item->due_date,
                    'reference_document' => (string) $item->document_number,
                ]);

                $this->openItems[(int) $item->id] = [
                    'detail_id' => $detailId,
                    'balance' => $item->balance,
                    'last_revaluation_rate' => $item->last_revaluation_rate,
                ];

                foreach ($remaining as $currency => $amount) {
                    $remaining[$currency] = bcsub($amount, $net[$currency], 2);
                }
            }

            // Lo que no es de una partida (o la diferencia de cambio): una línea más.
            if (bccomp($remaining['local'], '0', 2) !== 0 || bccomp($remaining['foreign'], '0', 2) !== 0 || bccomp($remaining['system'], '0', 2) !== 0) {
                $lines[] = $this->line($this->nextId(), $group, $remaining, ['description' => 'Saldo al '.$this->date()->format('d/m/Y')]);
            }
        }

        if ($lines === []) {
            return;
        }

        $documentType = $this->openingDocumentType();
        $entryId = $this->nextId();
        $period = DB::table('fiscal_periods')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'fiscal_periods.fiscal_year_id')
            ->where('fiscal_years.company_id', $this->companyId())
            ->where('fiscal_periods.start_date', '<=', $this->date()->format('Y-m-d'))
            ->where('fiscal_periods.end_date', '>=', $this->date()->format('Y-m-d'))
            ->value('fiscal_periods.id');

        $now = now()->format('Y-m-d H:i:s');

        $this->rows['journal_entries'][] = [
            'id' => $entryId,
            'company_id' => $this->companyId(),
            'document_type_id' => $documentType['id'],
            'document_number' => $documentType['number'],
            'number_series_id' => null,
            'series_number' => null,
            'document_date' => $this->date()->format('Y-m-d'),
            'posting_date' => $this->date()->format('Y-m-d'),
            'due_date' => null,
            'fiscal_period_id' => $period,
            'description' => 'Saldos al '.$this->date()->format('d/m/Y').' (respaldo desde el '.$this->options->from->format('d/m/Y').')',
            'status' => 'posted',
            'reversal_of_id' => null,
            'schedule_id' => null,
            'source_module' => 'contable',
            'business_partner_id' => null,
            'created_by' => null,
            'posted_by' => null,
            'posted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($lines as $number => $line) {
            $this->rows['journal_details'][] = ['journal_entry_id' => $entryId, 'line_number' => $number + 1] + $line;
        }

        $this->counts['accounts'] = $groups->pluck('account_id')->unique()->count();
        $this->counts['open_items'] = count($this->openItems);
    }

    /** @param array{local: string, foreign: string, system: string} $net */
    private function line(int $id, object $group, array $net, array $extra): array
    {
        $now = now()->format('Y-m-d H:i:s');

        return [
            'id' => $id,
            'account_id' => $group->account_id,
            'description' => $extra['description'] ?? null,
            'electronic_key' => null,
            'business_partner_id' => $group->business_partner_id,
            'cost_center_id' => null,
            'cost_allocation_rule_id' => null,
            'currency_id' => $group->currency_id,
            'exchange_rate_lc_fc' => null,
            'exchange_rate_fc_sc' => null,
            'debit_local' => $this->positive($net['local']),
            'credit_local' => $this->negative($net['local']),
            'debit_foreign' => $this->positive($net['foreign']),
            'credit_foreign' => $this->negative($net['foreign']),
            'debit_system' => $this->positive($net['system']),
            'credit_system' => $this->negative($net['system']),
            'due_date' => $extra['due_date'] ?? null,
            'reference_document' => $extra['reference_document'] ?? null,
            'reference_document_date' => null,
            'bank_reconciled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Las partidas que seguían abiertas el día anterior al período, con su
     * saldo a ese día. Una partida queda en cero si su asiento se anuló o si
     * se reconcilió antes; si no, es lo original menos lo aplicado.
     */
    private function openItemsAtOpening(): Collection
    {
        $day = $this->date()->format('Y-m-d');

        $items = DB::table('bp_open_items')
            ->join('journal_details', 'journal_details.id', '=', 'bp_open_items.origin_journal_detail_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $this->companyId())
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->where('journal_entries.posting_date', '<=', $day)
            ->select([
                'bp_open_items.*',
                'journal_details.account_id',
                'journal_details.exchange_rate_lc_fc',
                'journal_entries.id as entry_id',
                'journal_entries.status as entry_status',
                DB::raw('CASE WHEN journal_details.debit_local > 0 OR journal_details.debit_foreign > 0 THEN 1 ELSE 0 END as debit_side'),
            ])
            ->orderBy('bp_open_items.id')
            ->get();

        if ($items->isEmpty()) {
            return $items;
        }

        $applied = BackupState::appliedUntil($items->pluck('id')->all(), $day);
        $closed = BackupState::closedUntil($items, $day);

        return $items->map(function ($item) use ($applied, $closed) {
            $item->balance = isset($closed[$item->id])
                ? '0.00'
                : bcsub((string) $item->original_amount, $applied[$item->id] ?? '0.00', 2);
            $item->debit_side = (bool) $item->debit_side;

            return $item;
        })->filter(fn ($item) => bccomp($item->balance, '0', 2) > 0)->values();
    }

    /** @return array{id: int, number: int} el tipo APE de la compañía (o uno nuevo) y el número del asiento */
    private function openingDocumentType(): array
    {
        $existing = DB::table('document_types')
            ->where('company_id', $this->companyId())
            ->where('code', 'APE')
            ->first(['id', 'next_consecutive']);

        if ($existing) {
            $this->documentTypeChanges[(int) $existing->id] = ['next_consecutive' => (int) $existing->next_consecutive + 1];

            return ['id' => (int) $existing->id, 'number' => (int) $existing->next_consecutive];
        }

        $id = $this->nextId();
        $now = now()->format('Y-m-d H:i:s');

        $this->rows['document_types'][] = [
            'id' => $id,
            'company_id' => $this->companyId(),
            'code' => 'APE',
            'name' => 'Asiento de apertura (saldos iniciales)',
            'origin_module' => 'contable',
            'generates_journal' => true,
            'requires_electronic_key' => false,
            'bp_line_requirement' => 'due_date',
            'is_opening_type' => true,
            'is_reconciliation_type' => false,
            'is_closing_type' => false,
            'next_consecutive' => 2,
            'currency_mode' => 'libre',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return ['id' => $id, 'number' => 1];
    }

    // ── Existencias ─────────────────────────────────────────────────────

    private function buildStock(): void
    {
        $before = $this->options->fromDate();

        // Lo que había por artículo, almacén, lote y ubicación.
        $groups = DB::table('stock_journals')
            ->where('company_id', $this->companyId())
            ->where('posting_date', '<', $before)
            ->groupBy('item_id', 'warehouse_id', 'item_lot_id', 'warehouse_bin_id')
            ->selectRaw("item_id, warehouse_id, item_lot_id, warehouse_bin_id,
                SUM(CASE WHEN direction = 'in' THEN quantity WHEN direction = 'out' THEN -quantity ELSE 0 END) as net")
            ->orderBy('item_id')->orderBy('warehouse_id')
            ->get()
            ->filter(fn ($g) => bccomp($this->decimal($g->net), '0', 6) !== 0)
            ->groupBy(fn ($g) => $g->item_id.'|'.$g->warehouse_id);

        // El saldo y el costo promedio de cada artículo en cada almacén:
        // la última fila del kardex antes del período.
        $last = $this->lastStockRows($before);
        $journalEntryId = isset($this->rows['journal_entries']) ? $this->rows['journal_entries'][0]['id'] : null;
        $now = now()->format('Y-m-d H:i:s');
        $items = [];

        foreach ($last as $key => $row) {
            $balance = $this->decimal($row->balance_quantity);
            $parts = $groups->get($key, collect())->values();
            $sum = $parts->reduce(fn (string $carry, $g) => bcadd($carry, $this->decimal($g->net), 6), '0');

            // Si lo de los lotes y ubicaciones no da el saldo, la diferencia sin lote ni ubicación.
            if (bccomp($sum, $balance, 6) !== 0) {
                $parts->push((object) ['item_id' => $row->item_id, 'warehouse_id' => $row->warehouse_id, 'item_lot_id' => null, 'warehouse_bin_id' => null, 'net' => bcsub($balance, $sum, 6)]);
            }

            $running = '0';

            foreach ($parts as $part) {
                $quantity = $this->decimal($part->net);
                $running = bcadd($running, $quantity, 6);
                $absolute = ltrim($quantity, '-');

                $this->rows['stock_journals'][] = [
                    'id' => $this->nextId(),
                    'company_id' => $this->companyId(),
                    'item_id' => $row->item_id,
                    'warehouse_id' => $row->warehouse_id,
                    'warehouse_bin_id' => $part->warehouse_bin_id,
                    'item_lot_id' => $part->item_lot_id,
                    'inventory_document_line_id' => null,
                    'landed_cost_allocation_id' => null,
                    'journal_entry_id' => $journalEntryId,
                    'posting_date' => $this->date()->format('Y-m-d'),
                    'direction' => bccomp($quantity, '0', 6) >= 0 ? 'in' : 'out',
                    'quantity' => $absolute,
                    'unit_cost_local' => $this->decimal($row->avg_cost_local_after),
                    'unit_cost_foreign' => $this->decimal($row->avg_cost_foreign_after),
                    'total_cost_local' => $this->money(bcmul($absolute, $this->decimal($row->avg_cost_local_after), 6)),
                    'total_cost_foreign' => $this->money(bcmul($absolute, $this->decimal($row->avg_cost_foreign_after), 6)),
                    'balance_quantity' => $running,
                    'avg_cost_local_after' => $this->decimal($row->avg_cost_local_after),
                    'avg_cost_foreign_after' => $this->decimal($row->avg_cost_foreign_after),
                    'reversal_of_id' => null,
                    'created_by' => null,
                    'created_at' => $now,
                ];
            }

            if (bccomp($balance, '0', 6) !== 0 || $parts->isNotEmpty()) {
                $items[$row->item_id] = true;
            }
        }

        $this->counts['items'] = count($items);
    }

    /** @return Collection<string, object> «artículo|almacén» => la última fila antes de esa fecha */
    private function lastStockRows(string $before): Collection
    {
        $lastIds = DB::table('stock_journals')
            ->where('company_id', $this->companyId())
            ->where('posting_date', '<', $before)
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        // MAX(id) da la última en orden de registro; dentro de la misma fecha
        // es la última, y el kardex se registra en orden de fecha.
        return DB::table('stock_journals')
            ->whereIn('id', $lastIds)
            ->orderBy('item_id')->orderBy('warehouse_id')
            ->get()
            ->keyBy(fn ($row) => $row->item_id.'|'.$row->warehouse_id)
            ->filter(fn ($row) => bccomp($this->decimal($row->balance_quantity), '0', 6) !== 0);
    }

    // ── Vacaciones ──────────────────────────────────────────────────────

    private function buildVacations(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $balances = DB::table('vacation_movements')
            ->where('company_id', $this->companyId())
            ->where('movement_date', '<', $this->options->fromDate())
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(days) as days')
            ->get()
            ->filter(fn ($row) => bccomp($this->decimal($row->days, 4), '0', 4) !== 0);

        foreach ($balances as $row) {
            $this->rows['vacation_movements'][] = [
                'id' => $this->nextId(),
                'company_id' => $this->companyId(),
                'employee_id' => $row->employee_id,
                'type' => 'adjustment',
                'movement_date' => $this->date()->format('Y-m-d'),
                'days' => $this->decimal($row->days, 4),
                'from_date' => null,
                'to_date' => null,
                'payroll_period_id' => null,
                'payroll_entry_id' => null,
                'labor_settlement_id' => null,
                'amount' => null,
                'notes' => 'Saldo al '.$this->date()->format('d/m/Y').' (respaldo)',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->counts['employees'] = $balances->count();
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    private function nextId(): int
    {
        return $this->nextId--;
    }

    private function money(mixed $value): string
    {
        return $this->decimal($value, 2);
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        $value = (string) ($value ?? '0');

        if (str_contains($value, 'E') || str_contains($value, 'e')) {
            $value = number_format((float) $value, $scale, '.', '');
        }

        // bcadd con 0 normaliza la escala (y redondea hacia cero lo de más).
        return bcadd($this->round($value, $scale), '0', $scale);
    }

    private function round(string $value, int $scale): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-') ? bcsub($value, $offset, $scale) : bcadd($value, $offset, $scale);
    }

    private function positive(string $net): string
    {
        return bccomp($net, '0', 2) > 0 ? $net : '0.00';
    }

    private function negative(string $net): string
    {
        return bccomp($net, '0', 2) < 0 ? ltrim($net, '-') : '0.00';
    }
}
