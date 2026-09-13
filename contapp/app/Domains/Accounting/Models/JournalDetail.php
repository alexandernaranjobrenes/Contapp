<?php

namespace App\Domains\Accounting\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Tax\Models\JournalDetailTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * No lleva company_id propio: se filtra a través de journal_entry_id -> journal_entries.company_id.
 */
class JournalDetail extends Model
{
    protected $fillable = [
        'journal_entry_id', 'line_number', 'account_id', 'description', 'electronic_key', 'business_partner_id',
        'cost_center_id', 'cost_allocation_rule_id',
        'currency_id', 'exchange_rate_lc_fc', 'exchange_rate_fc_sc',
        'debit_local', 'credit_local', 'debit_foreign', 'credit_foreign', 'debit_system', 'credit_system',
        'due_date', 'reference_document', 'reference_document_date', 'bank_reconciled',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'reference_document_date' => 'date',
            'bank_reconciled' => 'boolean',
            'exchange_rate_lc_fc' => 'decimal:6',
            'exchange_rate_fc_sc' => 'decimal:6',
            'debit_local' => 'decimal:2',
            'credit_local' => 'decimal:2',
            'debit_foreign' => 'decimal:2',
            'credit_foreign' => 'decimal:2',
            'debit_system' => 'decimal:2',
            'credit_system' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /**
     * Presente si esta fila nació de repartir una línea de asiento entre
     * varios centros de costo (ver PostJournalService::post()) — el centro
     * específico de ESTA fila sigue en cost_center_id; esto solo etiqueta
     * de qué norma vino, para trazabilidad.
     */
    public function costAllocationRule(): BelongsTo
    {
        return $this->belongsTo(CostAllocationRule::class);
    }

    /**
     * Presente solo si esta línea ES el impuesto derivado de otra línea
     * (ver PostJournalService::attachTax()) — una línea "normal" no tiene.
     */
    public function tax(): HasOne
    {
        return $this->hasOne(JournalDetailTax::class);
    }

    /**
     * Puede haber más de una: desde que account_reconciliation_lines admite
     * reconciliación parcial (2026-09-07), un mismo movimiento se puede ir
     * consumiendo en varios eventos de reconciliación distintos mientras le
     * quede saldo disponible (ver availableReconciliationAmount()).
     */
    public function accountReconciliationLines(): HasMany
    {
        return $this->hasMany(AccountReconciliationLine::class);
    }

    /**
     * Monto original del movimiento en valor absoluto (signo aparte) —
     * base sobre la que se calcula cuánto queda disponible para reconciliar.
     */
    public function originalAmount(): string
    {
        $net = bcsub((string) $this->debit_local, (string) $this->credit_local, 2);

        return bccomp($net, '0.00', 2) < 0 ? bcmul($net, '-1', 2) : $net;
    }

    /**
     * Cuánto de este movimiento ya se consumió en reconciliaciones
     * (completas o parciales) previas.
     */
    public function reconciledAmount(): string
    {
        return (string) $this->accountReconciliationLines()->sum('amount');
    }

    /**
     * Saldo libre para una reconciliación nueva — cero significa que ya
     * está completamente reconciliado, no que nunca haya tenido líneas.
     */
    public function availableReconciliationAmount(): string
    {
        return bcsub($this->originalAmount(), $this->reconciledAmount(), 2);
    }
}
