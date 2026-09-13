<?php

namespace App\Domains\Accounting\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fiscal_year_id', 'account_id', 'business_partner_id',
        'debit_local', 'credit_local', 'debit_foreign', 'credit_foreign', 'currency_id',
        'imported_by', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'debit_local' => 'decimal:2',
            'credit_local' => 'decimal:2',
            'debit_foreign' => 'decimal:2',
            'credit_foreign' => 'decimal:2',
            'imported_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
