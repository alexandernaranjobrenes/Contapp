<?php

namespace App\Domains\Inventory\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de compra. Espejo de la orden de pedido: no mueve plata ni
 * contabilidad, solo declara qué mercancía viene en camino hasta que se
 * reciba, se cancele o se cierre.
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUSES = [
        'open' => 'Abierta',
        'partially_received' => 'Parcialmente recibida',
        'received' => 'Recibida',
        'cancelled' => 'Cancelada',
        'closed' => 'Cerrada con saldo',
    ];

    /** Estados en los que la orden todavía espera mercancía. */
    public const PENDING_STATUSES = ['open', 'partially_received'];

    protected $fillable = [
        'company_id', 'number', 'business_partner_id', 'order_date', 'expected_date',
        'status', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_number');
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    /**
     * Recepciones hechas contra esta orden. Una orden puede recibirse en
     * varias entregas parciales, así que son varias.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(InventoryDocument::class);
    }

    /**
     * Una orden suma a "en camino" mientras espere mercancía. Recibida,
     * cancelada o cerrada ya no espera nada.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', self::PENDING_STATUSES);
    }

    /** Lo que falta por recibir de toda la orden. */
    public function pendingQuantity(): string
    {
        return $this->lines->reduce(
            fn (string $carry, PurchaseOrderLine $line) => bcadd($carry, $line->pending(), 6),
            '0.000000'
        );
    }
}
