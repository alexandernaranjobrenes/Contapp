<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una unidad física identificada. A diferencia de un lote, no tiene cantidad:
 * la fila ES la unidad, y su estado más su almacén dicen dónde está.
 *
 * No lleva company_id: cuelga de item_id y es el CompanyScope de Item el que
 * la aísla, mismo criterio que ItemLot y WarehouseBin.
 */
class ItemSerial extends Model
{
    use HasFactory;

    public const STATUSES = [
        'in_stock' => 'En existencia',
        'issued' => 'Entregada',
        'scrapped' => 'Dada de baja',
    ];

    protected $fillable = [
        'item_id', 'serial_number', 'status', 'warehouse_id', 'warehouse_bin_id', 'item_lot_id',
        'received_document_id', 'issued_document_id', 'received_at', 'issued_at',
        'warranty_until', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'issued_at' => 'date',
            'warranty_until' => 'date',
        ];
    }

    /**
     * Sin esto las fechas se serializan a JSON con hora y zona en vez de
     * "2026-10-15" — mismo criterio que ItemLot.
     */
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

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ItemLot::class, 'item_lot_id');
    }

    public function receivedDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'received_document_id');
    }

    public function issuedDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'issued_document_id');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', 'in_stock');
    }

    /**
     * En garantía EN una fecha, no "hoy": una reclamación se evalúa contra el
     * día en que se presentó, no contra el reloj del servidor. Mismo criterio
     * que ItemLot::isExpiredOn().
     *
     * Una serie sin fecha de garantía no está en garantía: a diferencia del
     * vencimiento de un lote —donde "sin fecha" significa que no caduca—, acá
     * la ausencia del dato significa que no se registró ninguna cobertura.
     */
    public function isUnderWarrantyOn(\DateTimeInterface $date): bool
    {
        return $this->warranty_until !== null
            && $date->format('Y-m-d') <= $this->warranty_until->format('Y-m-d');
    }
}
