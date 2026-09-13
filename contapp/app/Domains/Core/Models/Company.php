<?php

namespace App\Domains\Core\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Raíz del tenant. No usa BelongsToCompany: es la propia unidad de aislamiento,
 * no algo que se filtre por company_id.
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id', 'legal_name', 'trade_name', 'tax_id', 'address', 'country_code',
        'local_currency_id', 'foreign_currency_id', 'system_currency_id',
        'timezone', 'logo_path', 'status',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function localCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'local_currency_id');
    }

    public function foreignCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'foreign_currency_id');
    }

    public function systemCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'system_currency_id');
    }

    public function accountMaskConfig(): HasOne
    {
        return $this->hasOne(AccountMaskConfig::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['is_default', 'status'])
            ->withTimestamps();
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }
}
