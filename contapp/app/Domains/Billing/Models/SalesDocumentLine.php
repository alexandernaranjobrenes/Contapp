<?php

namespace App\Domains\Billing\Models;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sin BelongsToCompany: el aislamiento lo hereda de su documento.
 */
class SalesDocumentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_document_id', 'line_number', 'item_id', 'warehouse_id', 'warehouse_bin_id',
        'item_code', 'cabys_code', 'description', 'unit_code', 'is_service',
        'quantity', 'unit_price', 'total_amount',
        'discount_code', 'discount_reason', 'discount_amount',
        'subtotal', 'tax_amount', 'exonerated_amount', 'line_total', 'vin_or_serial',
    ];

    protected function casts(): array
    {
        return [
            'is_service' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SalesLineTax::class, 'sales_document_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
