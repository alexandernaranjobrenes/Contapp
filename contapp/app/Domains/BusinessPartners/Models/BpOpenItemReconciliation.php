<?php

namespace App\Domains\BusinessPartners\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reconciliación interna: agrupa N partidas abiertas (bp_open_items) de UN
 * MISMO socio de negocio cuyo neto suma exactamente cero — "esto de aquí
 * cancela aquello de allá" (ej. un saldo inicial que quedó igual a un pago
 * que ya se había contabilizado por fuera de ApplyPaymentService), sin
 * generar ningún asiento nuevo. Ver OpenItemNettingService.
 *
 * Nunca cruza socios: siempre las partidas de uno solo, para no arriesgar
 * cancelar el saldo de un socio contra el de otro por error (mismo criterio
 * que ya documenta AccountReconciliation para movimientos de cuenta).
 */
class BpOpenItemReconciliation extends Model
{
    protected $fillable = ['business_partner_id', 'reconciled_by', 'reconciled_at'];

    protected function casts(): array
    {
        return [
            'reconciled_at' => 'datetime',
        ];
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BpOpenItemReconciliationLine::class);
    }
}
