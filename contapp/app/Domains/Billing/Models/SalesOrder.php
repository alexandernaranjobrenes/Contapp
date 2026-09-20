<?php

namespace App\Domains\Billing\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de pedido. No mueve plata ni contabilidad: solo aparta mercancía
 * hasta que se facture o se cancele.
 */
class SalesOrder extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUSES = [
        'open' => 'Abierta',
        'invoiced' => 'Facturada',
        'cancelled' => 'Cancelada',
    ];

    protected $fillable = [
        'company_id', 'number', 'business_partner_id', 'order_date', 'delivery_date',
        'status', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'delivery_date' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_number');
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    /**
     * Comprobantes emitidos a partir de esta orden. Una orden puede facturarse
     * en varias entregas, así que son varios.
     */
    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }

    /**
     * Una orden reserva mientras esté abierta. Facturada o cancelada ya soltó
     * su mercancía y no aparta nada.
     */
    public function scopeReserving(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
