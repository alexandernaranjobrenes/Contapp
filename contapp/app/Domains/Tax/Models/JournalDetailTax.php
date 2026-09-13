<?php

namespace App\Domains\Tax\Models;

use App\Domains\Accounting\Models\JournalDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No lleva company_id propio: se filtra a través de journal_detail_id ->
 * journal_details -> journal_entries.company_id. Es la fuente de verdad de
 * los reportes fiscales (snapshot: nunca se recalcula desde journal_details
 * en crudo, ver docs/decisiones.md 2026-08-04).
 */
class JournalDetailTax extends Model
{
    protected $fillable = ['journal_detail_id', 'tax_rate_id', 'taxable_base', 'tax_amount'];

    protected function casts(): array
    {
        return [
            'taxable_base' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    public function journalDetail(): BelongsTo
    {
        return $this->belongsTo(JournalDetail::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
