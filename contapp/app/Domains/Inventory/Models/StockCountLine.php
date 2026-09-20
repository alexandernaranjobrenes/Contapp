<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'stock_count_id', 'line_number', 'item_id', 'warehouse_id', 'warehouse_bin_id',
        'theoretical_quantity', 'counted_quantity', 'unit_cost_local', 'description',
    ];

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class);
    }

    /** Null mientras nadie haya escrito la cantidad contada. */
    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /** Contado menos teórico: positivo es sobrante, negativo es faltante. */
    public function difference(): string
    {
        return bcsub((string) ($this->counted_quantity ?? '0'), (string) $this->theoretical_quantity, 6);
    }

    /** Lo que la diferencia vale al costo promedio congelado al abrir. */
    public function differenceValue(): string
    {
        return number_format((float) bcmul($this->difference(), (string) $this->unit_cost_local, 12), 2, '.', '');
    }
}
