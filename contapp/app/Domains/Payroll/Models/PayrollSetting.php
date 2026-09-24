<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Las cuentas y parámetros de la planilla de una compañía. Una sola fila.
 */
class PayrollSetting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'salary_expense_account_id', 'net_payable_account_id', 'income_tax_payable_account_id',
        'document_type_id', 'vacation_days_per_month', 'max_deduction_percentage',
    ];

    public function salaryExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'salary_expense_account_id');
    }

    public function netPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'net_payable_account_id');
    }

    public function incomeTaxPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_tax_payable_account_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
