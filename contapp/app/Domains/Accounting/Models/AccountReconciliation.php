<?php

namespace App\Domains\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reconciliación interna: agrupa N movimientos (journal_details) de UNA
 * MISMA cuenta cuyo neto en moneda local suma exactamente cero — "esto de
 * aquí cancela aquello de allá", sin generar ningún asiento nuevo (a
 * diferencia de ApplyPaymentService, que sí liga un pago a una partida con
 * saldo). No lleva company_id propio: se filtra vía account_id ->
 * chart_of_accounts.company_id (mismo patrón que bp_open_items/journal_details).
 *
 * Deliberadamente fuera de esto: movimientos con business_partner_id — esos
 * ya tienen su propio mecanismo formal (BpOpenItem/ApplyPaymentService) que
 * respeta el límite de cada socio; mezclarlos acá arriesgaría cancelar el
 * saldo de un socio contra el de otro por error.
 */
class AccountReconciliation extends Model
{
    protected $fillable = ['account_id', 'reconciled_by', 'reconciled_at'];

    protected function casts(): array
    {
        return [
            'reconciled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccountReconciliationLine::class);
    }
}
