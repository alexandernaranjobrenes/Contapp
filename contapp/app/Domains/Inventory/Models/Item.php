<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'item_group_id', 'uom_id', 'barcode',
        'is_inventory_item', 'is_sales_item', 'is_purchase_item', 'tax_rate_id',
        'avg_cost_local', 'avg_cost_foreign', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_inventory_item' => 'boolean',
            'is_sales_item' => 'boolean',
            'is_purchase_item' => 'boolean',
        ];
    }

    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class);
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(ItemWarehouse::class);
    }

    /**
     * Existencia total del artículo: se deriva sumando los almacenes, nunca
     * se almacena (CLAUDE.md). Solo el costo promedio se guarda, por ser
     * dependiente de la trayectoria — ver docs/decisiones.md 2026-09-13.
     */
    public function onHand(): string
    {
        return (string) $this->stockLevels()->sum('on_hand');
    }

    /**
     * Tipo de cambio al que está congelado el stock de este artículo. Null
     * cuando todavía no hay costo (artículo nuevo o existencia agotada):
     * en ese caso la entrada que venga fija el promedio desde cero.
     */
    public function frozenExchangeRate(): ?string
    {
        if (bccomp((string) $this->avg_cost_foreign, '0.000000', 6) === 0) {
            return null;
        }

        return bcdiv((string) $this->avg_cost_local, (string) $this->avg_cost_foreign, 6);
    }
}
