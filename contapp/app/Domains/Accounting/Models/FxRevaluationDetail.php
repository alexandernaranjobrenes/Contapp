<?php

namespace App\Domains\Accounting\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No lleva company_id propio: se filtra a través de fx_revaluation_run_id -> fx_revaluation_runs.company_id.
 */
class FxRevaluationDetail extends Model
{
    protected $fillable = [
        'fx_revaluation_run_id', 'account_id', 'business_partner_id',
        'foreign_balance', 'historical_local_amount', 'revalued_local_amount', 'difference', 'journal_detail_id',
    ];

    protected function casts(): array
    {
        return [
            'foreign_balance' => 'decimal:2',
            'historical_local_amount' => 'decimal:2',
            'revalued_local_amount' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FxRevaluationRun::class, 'fx_revaluation_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function journalDetail(): BelongsTo
    {
        return $this->belongsTo(JournalDetail::class);
    }
}
