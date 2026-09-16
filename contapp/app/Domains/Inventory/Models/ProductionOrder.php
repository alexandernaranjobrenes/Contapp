<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'item_id', 'warehouse_id', 'planned_quantity', 'produced_quantity',
        'order_date', 'description', 'status', 'variance_journal_entry_id', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InventoryDocument::class);
    }

    public function varianceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'variance_journal_entry_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Costo acumulado en proceso: lo emitido menos lo ya descargado al
     * producto terminado. Se DERIVA de los movimientos de la orden en vez de
     * almacenarse — es una suma, no un valor dependiente de la trayectoria
     * como el costo promedio (CLAUDE.md: los saldos se calculan).
     */
    public function wipBalance(): string
    {
        // Sin CompanyScope: la orden ya acota el universo a su propia
        // compañía, y este cálculo debe ser correcto también desde un job sin
        // CurrentCompany seteado (mismo criterio que los services).
        $rows = StockJournal::withoutGlobalScope(CompanyScope::class)
            ->join('inventory_document_lines', 'inventory_document_lines.id', '=', 'stock_journals.inventory_document_line_id')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('inventory_documents.production_order_id', $this->id)
            ->where('inventory_documents.status', 'posted')
            ->get(['stock_journals.total_cost_local', 'inventory_documents.operation']);

        $balance = '0.00';

        foreach ($rows as $row) {
            $balance = $row->operation === 'production_issue'
                ? bcadd($balance, (string) $row->total_cost_local, 2)
                : bcsub($balance, (string) $row->total_cost_local, 2);
        }

        return $balance;
    }
}
