<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kardex inviolable. Append-only: no tiene updated_at y nada del sistema
 * edita ni borra una fila — una corrección es un documento de reversión que
 * agrega filas nuevas, igual que un asiento contabilizado.
 */
class StockJournal extends Model
{
    use BelongsToCompany, HasFactory;

    public const UPDATED_AT = null;

    /**
     * 'revaluation' no mueve unidades: solo cambia el valor del inventario
     * (landed cost, diferencia de precio). Queda en el kardex igual que un
     * movimiento para que el promedio resultante tenga una fila que lo explique.
     */
    public const DIRECTIONS = [
        'in' => 'Entrada',
        'out' => 'Salida',
        'revaluation' => 'Revaluación',
    ];

    protected $fillable = [
        'company_id', 'item_id', 'warehouse_id', 'warehouse_bin_id', 'inventory_document_line_id',
        'landed_cost_allocation_id',
        'journal_entry_id', 'posting_date', 'direction', 'quantity',
        'unit_cost_local', 'unit_cost_foreign', 'total_cost_local', 'total_cost_foreign',
        'balance_quantity', 'avg_cost_local_after', 'avg_cost_foreign_after',
        'reversal_of_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(InventoryDocumentLine::class, 'inventory_document_line_id');
    }
}
