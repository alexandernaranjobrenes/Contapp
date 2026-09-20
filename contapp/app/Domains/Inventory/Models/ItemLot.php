<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lote de un artículo: de dónde viene y cuándo vence. Nunca guarda costo
 * —el costo es promedio global por artículo, igual que con las ubicaciones—
 * solo identidad y vigencia.
 *
 * No lleva company_id: cuelga de item_id y es el CompanyScope de Item el que
 * lo aísla, mismo criterio que WarehouseBin con su almacén.
 */
class ItemLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'code', 'expires_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
        ];
    }

    /**
     * Sin esto, expires_at se serializa a JSON con hora y zona en vez de
     * "2026-10-15" — mismo criterio que CostCenter y BusinessPartner.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(ItemLotStock::class);
    }

    /**
     * Vencido EN una fecha, no "vencido hoy": lo que decide si un lote se
     * puede despachar es la fecha de contabilización del movimiento, no el
     * reloj del servidor. Contabilizar un despacho retroactivo al 1.º de
     * marzo debe evaluarse contra ese día.
     *
     * Un lote sin fecha de vencimiento nunca vence.
     */
    public function isExpiredOn(\DateTimeInterface $date): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->format('Y-m-d') < $date->format('Y-m-d');
    }

    /**
     * Despachable en esa fecha: activo (ni bloqueado ni dado de baja) y no
     * vencido. Es la condición que exige ItemLotResolver en toda salida.
     */
    public function isIssuableOn(\DateTimeInterface $date): bool
    {
        return $this->status === 'active' && ! $this->isExpiredOn($date);
    }

    /**
     * Orden FEFO (First Expired, First Out): primero el que vence antes. Los
     * lotes sin vencimiento van al final — no es que "no venzan pronto", es
     * que no compiten en ese criterio y no deberían desplazar a uno que sí
     * tiene fecha.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expires_at IS NULL, expires_at ASC')->orderBy('code');
    }
}
