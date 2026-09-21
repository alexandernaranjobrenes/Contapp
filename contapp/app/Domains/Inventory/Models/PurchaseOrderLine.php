<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'purchase_order_id', 'line_number', 'item_id', 'warehouse_id',
        'quantity', 'quantity_received', 'unit_cost_local', 'description',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Lo que esta línea sigue esperando: lo pedido menos lo ya recibido. */
    public function pending(): string
    {
        return bcsub((string) $this->quantity, (string) $this->quantity_received, 6);
    }
}
