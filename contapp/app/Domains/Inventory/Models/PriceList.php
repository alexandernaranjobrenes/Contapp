<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lista de precios de venta. Precio y costo no se tocan: el costo lo mantiene
 * el motor de movimientos y sale de lo que se pagó; el precio es una decisión
 * comercial. Se encuentran solo en el margen, que es un reporte.
 */
class PriceList extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'currency_id', 'prices_include_tax',
        'valid_from', 'valid_to', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
            'is_default' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * Una lista fuera de vigencia no se borra ni se inactiva: sigue ahí para
     * consultar a qué precio se vendió el año pasado. Lo que no hace es
     * aplicar a una venta de hoy.
     */
    public function isValidOn(string $date): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->valid_from !== null && $date < $this->valid_from->format('Y-m-d')) {
            return false;
        }

        return $this->valid_to === null || $date <= $this->valid_to->format('Y-m-d');
    }
}
