<?php

namespace App\Domains\Billing\Models;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'sales_order_id', 'line_number', 'item_id', 'warehouse_id',
        'quantity', 'quantity_invoiced', 'unit_price', 'description',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Lo que esta línea sigue apartando: lo pedido menos lo ya facturado.
     */
    public function pending(): string
    {
        return bcsub((string) $this->quantity, (string) $this->quantity_invoiced, 6);
    }
}
