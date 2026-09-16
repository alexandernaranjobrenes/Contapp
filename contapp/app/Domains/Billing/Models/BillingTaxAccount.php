<?php

namespace App\Domains\Billing\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Puente entre la tarifa de IVA de la norma y lo que el ERP necesita de ella:
 * la cuenta del débito fiscal y el indicador de impuesto equivalente del
 * proyecto, que es lo que mantiene vivo el reporte de IVA ya existente.
 */
class BillingTaxAccount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'iva_rate_code', 'account_id', 'tax_rate_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
