<?php

namespace App\Domains\Billing\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contra qué cuenta se debita cada medio de pago en una venta de contado.
 */
class BillingPaymentAccount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'method_code', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
