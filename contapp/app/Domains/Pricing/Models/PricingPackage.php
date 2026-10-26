<?php

namespace App\Domains\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un paquete armado (CLAUDE.md secc. 42): un punto de partida que el
 * visitante ajusta en /precios.
 *
 * - billing: si se ofrece por mes, por año o de las dos formas (el
 *   visitante elige).
 * - discount_percent: el descuento sobre lo que trae el paquete, mientras
 *   no se le quite nada. Agregar funciones lo conserva (las agregadas van a
 *   precio de lista); quitar una de las suyas lo vuelve «Personalizado».
 */
class PricingPackage extends Model
{
    public const BILLING = ['monthly', 'annual', 'both'];

    protected $fillable = [
        'name', 'slug', 'tagline', 'description', 'badge', 'is_highlighted', 'billing', 'discount_percent',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_highlighted' => 'boolean',
            'is_active' => 'boolean',
            'discount_percent' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(PricingFeature::class, 'pricing_package_features', 'package_id', 'feature_id')
            ->withPivot('quantity');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function discountBasisPoints(): int
    {
        return (int) round((float) $this->discount_percent * 100);
    }
}
