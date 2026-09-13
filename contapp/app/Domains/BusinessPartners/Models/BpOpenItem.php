<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\JournalDetail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * No lleva company_id propio: se filtra a través de business_partner_id ->
 * business_partners.company_id (mismo patrón que journal_details/fiscal_periods).
 */
class BpOpenItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_partner_id', 'origin_journal_detail_id', 'document_type_code',
        'document_number', 'due_date', 'original_amount', 'currency_id', 'last_revaluation_rate',
        'applied_amount', 'balance', 'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'original_amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'last_revaluation_rate' => 'decimal:6',
        ];
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function originJournalDetail(): BelongsTo
    {
        return $this->belongsTo(JournalDetail::class, 'origin_journal_detail_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function paymentApplications(): HasMany
    {
        return $this->hasMany(BpPaymentApplication::class, 'open_item_id');
    }

    public function reconciliationLine(): HasOne
    {
        return $this->hasOne(BpOpenItemReconciliationLine::class, 'bp_open_item_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    /**
     * Saldo pendiente con signo, mismo criterio que ya usa
     * AccountReconciliationService (débito positivo, crédito negativo) —
     * pero sobre el SALDO restante, no el monto original de la línea, porque
     * una partida parcial (ya con algún pago formal aplicado) tiene menos
     * pendiente que lo que se contabilizó originalmente. Requiere
     * originJournalDetail cargado (o lo carga perezosamente acá).
     */
    public function signedBalance(): string
    {
        $detail = $this->relationLoaded('originJournalDetail') ? $this->originJournalDetail : $this->originJournalDetail()->first();
        $sign = bccomp((string) $detail->debit_local, '0.00', 2) > 0 ? '1' : '-1';

        return bcmul($sign, (string) $this->balance, 2);
    }
}
