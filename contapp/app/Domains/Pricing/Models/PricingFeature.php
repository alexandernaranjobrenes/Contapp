<?php

namespace App\Domains\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una función que se vende en los planes (CLAUDE.md secc. 42): un módulo
 * (Inventario), un servicio (Conti) o una cantidad (compañías, usuarios).
 *
 * - kind «toggle» se elige o no; «quantity» cobra por unidad, con las
 *   primeras included_quantity sin costo y un tope opcional.
 * - Los precios, mensual y anual, en colones y en dólares. El anual vacío
 *   es 12 veces el mensual.
 * - is_required: va siempre (la Contabilidad). requirements: las que
 *   necesita para tener sentido (Facturación necesita Inventario): al
 *   elegirla se suman solas.
 * - module_key: el módulo de Pivox que da. Hoy es informativo: la licencia
 *   todavía no limita módulos (decidido con el usuario el 2026-10-25).
 */
class PricingFeature extends Model
{
    public const KIND_TOGGLE = 'toggle';

    public const KIND_QUANTITY = 'quantity';

    protected $fillable = [
        'key', 'name', 'description', 'icon', 'group', 'kind', 'unit_singular', 'unit_plural', 'included_quantity',
        'max_quantity', 'monthly_crc', 'annual_crc', 'monthly_usd', 'annual_usd', 'is_required', 'is_active',
        'module_key', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'included_quantity' => 'integer',
            'max_quantity' => 'integer',
            'monthly_crc' => 'decimal:2',
            'annual_crc' => 'decimal:2',
            'monthly_usd' => 'decimal:2',
            'annual_usd' => 'decimal:2',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Las que necesita para tener sentido. */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'pricing_feature_requirements', 'feature_id', 'required_feature_id');
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(PricingPackage::class, 'pricing_package_features', 'feature_id', 'package_id')->withPivot('quantity');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function isQuantity(): bool
    {
        return $this->kind === self::KIND_QUANTITY;
    }

    /**
     * El precio de una unidad, en céntimos, por mes o por año.
     *
     * @param  'monthly'|'annual'  $cycle
     * @param  'CRC'|'USD'  $currency
     */
    public function priceCents(string $cycle, string $currency): int
    {
        $suffix = strtolower($currency);
        $monthly = (int) round((float) $this->{"monthly_{$suffix}"} * 100);

        if ($cycle === 'monthly') {
            return $monthly;
        }

        $annual = $this->{"annual_{$suffix}"};

        return $annual === null ? $monthly * 12 : (int) round((float) $annual * 100);
    }
}
