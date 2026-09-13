<?php

namespace App\Domains\Banking\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'gl_account_id', 'bank_name', 'account_number', 'currency_id'];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }
}
