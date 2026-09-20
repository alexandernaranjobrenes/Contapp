<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Toma física de inventario. Congela la existencia teórica a una fecha de
 * corte, se imprime para contar en papel, y al cerrarse genera el ajuste.
 */
class StockCount extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUSES = [
        'open' => 'En conteo',
        'posted' => 'Cerrada',
        'cancelled' => 'Cancelada',
    ];

    protected $fillable = [
        'company_id', 'number', 'document_type_id', 'cutoff_date', 'warehouse_id',
        'item_group_id', 'blind', 'status', 'inventory_document_id', 'description',
        'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'posted_at' => 'datetime',
            'blind' => 'boolean',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('line_number');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** El ajuste de inventario que generó al cerrarse; null si no hubo diferencias. */
    public function inventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
